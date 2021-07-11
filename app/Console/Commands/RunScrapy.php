<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WebsiteList;
use App\Models\Deployment;
use App\Services\Filesystem\Filesystem;
use App\Services\Scripts\Parser;
use App\Services\Scripts\Runner as Process;
use lluminate\Log\Writer;
use Symfony\Component\Process\Process as SymfonyProcess;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Schema;
use App\Services\CustomBladeCompiler;
use Illuminate\Support\Facades\DB;

use Illuminate\Database\Schema\Blueprint;
class RunScrapy extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = "run:scrapy {--debug} {site}";

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = "Run Scrapy Bot";

	/**
	 * Create a new command instance.
	 *
	 * @return void
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Execute the console command.
	 *
	 * @return int
	 */
	public function handle()
	{
		$site = $this->argument("site");
		$debug = $this->option("debug");
		$web = WebsiteList::where("db_prefix", $site)->firstOrFail();

		$filesystem = new Filesystem();

		$private_key = $filesystem->tempnam("/tmp/", "key");
		$wrapper_file = $filesystem->tempnam("/tmp/", "ssh");

		try {
			$web->update(["service_at" => now()->format("Y-m-d H:i:s"), "service_status" => "running"]);

			$parser = new Parser($filesystem);
			// $symproc = new SymfonyProcess([]);
			// $loger = new Writer();
			// $process = new Process();

			$filesystem->put($private_key, $web->git_ssh_key);
			$filesystem->chmod($private_key, 0600);

			$wrapper = $parser->parseFile("tools.SSHWrapperScript", [
				"private_key" => $private_key,
			]);

			$filesystem->put($wrapper_file, $wrapper);
			$filesystem->chmod($wrapper_file, 0755);

			$command = $parser->parseFile("tools.MirrorGitRepository", [
				"wrapper_file" => $wrapper_file,
				"mirror_path" => $web->mirrorPath(),
				"repository" => $web->git_repo,
			]);

			$this->processCmd($command);

			foreach (["tag", "branch"] as $ref) {
				$commandListGitReferences = $parser->parseFile("tools.ListGitReferences", [
					"mirror_path" => $web->mirrorPath(),
					"git_reference" => $ref,
				]);
				$this->processCmd($commandListGitReferences);
			}

			$GetCommitDetails = $parser->parseFile("tools.GetCommitDetails", [
				"mirror_path" => $web->mirrorPath(),
				"git_reference" => $web->git_branch,
			]);
			$git_info = $this->processCmd($GetCommitDetails, true);
			list($commit, $committer, $email) = explode("\x09", $git_info);
			$this->info($git_info);
			$deployment = Deployment::firstOrCreate(
				[
					"website_id" => $web->id,
					"commit" => $commit,
				],
				[
					"committer" => $committer,
					"committer_email" => $email,
					"branch" => $web->git_branch,
					"status" => 1,
					"is_webhook" => 0,
					"reason" => "",
					"source" => "Auto deploy",
				]
			);
			$deployment->update(["started_at" => now()->format("Y-m-d H:i:s")]);

			$tmp_dir = "clone_" . $web->id . "_" . $deployment->release_id;
			$this->archive = $web->id . "_" . $deployment->release_id . ".tar.gz";
			$release_path = "/var/scrapy/" . $web->db_prefix . "_" . $deployment->short_commit;

			$commandArchive = $parser->parseFile("deploy.CreateRelease", [
				"deployment" => $deployment->id,
				"mirror_path" => $web->mirrorPath(),
				"custom_env" => $web->custom_env,
				"keep_day" => $web->keep_day,
				"scripts_path" => resource_path("scripts/"),
				"tmp_path" => storage_path("app/tmp/" . $tmp_dir),
				"sha" => $deployment->commit,
				"release_archive" => storage_path("app/" . $this->archive),
				"release_path" => $release_path,
			]);

			if (file_exists($release_path . "/.env")) {
				$env = file_get_contents($release_path . "/.env");

				$appEnv = $this->parseEnv($env);

				$customEnv = $this->parseEnv($web->custom_env);
				$mergeEnv = array_merge($appEnv, $fixEnv, $customEnv);
				$this->writeEnv($release_path . "/.env", $mergeEnv);
			}

			//DB::connection('scrapy')->table($web->db_prefix . '_forums')->truncate();

			$this->processCmd($commandArchive);
			if (Schema::connection("scrapy")->hasTable($web->db_prefix . "_forums") === true) {
				$forums = DB::connection("scrapy")->table($web->db_prefix . "_forums");
				// $forums->truncate();
			}
			$this->line($web->command_before_new);
			$this->processCmd(["cd " . $release_path, $web->command_before_new]);
			$keys_contents = collect(DB::connection("scrapy")->select(DB::raw("SHOW KEYS from " . $web->db_prefix . "_contents")))->pluck("Column_name");

			Schema::connection("scrapy")->table($web->db_prefix . "_contents", function (Blueprint $table) use ($web, $keys_contents) {
				$index = ["create_date", "import_date", "update_date", "is_updated", "room_id", "cid"];
				foreach ($index as $idx) {
					if (!in_array($idx, $keys_contents->toArray())) {
						if (Schema::connection("scrapy")->hasColumn($web->db_prefix . "_contents", $idx)) {
							$table->index($idx);
						}
					}
				}
			});

			if (Schema::connection("scrapy")->hasTable($web->db_prefix . "_forums") === true) {
				$forums = DB::connection("scrapy")->table($web->db_prefix . "_forums");
				$command_run_new = CustomBladeCompiler::render($web->command_run_new, ["forums" => $forums]);
			} else {
				$command_run_new = CustomBladeCompiler::render($web->command_run_new, []);
			}

			if (Schema::connection("scrapy")->hasTable($web->db_prefix . "_contents") === true) {
				$contents = DB::connection("scrapy")->table($web->db_prefix . "_contents");
				// $contents->truncate();
			}

			$this->processCmd(["cd " . $release_path, $command_run_new]);

			if ($debug) {
				$this->call("wisesight:postmq", ["--site" => $web->db_prefix, "--debug"]);
			} else {
				$this->call("wisesight:postmq", ["--site" => $web->db_prefix]);
			}

			$web->update(["service_at" => now()->format("Y-m-d H:i:s"), "service_status" => "schedule", "issue_detail" => ""]);

			unlink($private_key);
			unlink($wrapper_file);

			return 0;
		} catch (\Exception $e) {
			$this->warn($e->getMessage());
			$web->update(["service_at" => now()->format("Y-m-d H:i:s"), "service_status" => "failed", "issue_detail" => $e->getMessage()]);

			unlink($private_key);
			unlink($wrapper_file);
			return 1;
		}
	}
	function writeEnv($path, $env)
	{
		$content = "";
		foreach ($env as $key => $val) {
			$line = implode("=", [$key, $val]);
			if ($line) {
				if (trim($content)) {
					$content .= "\n";
				}
				$content .= $line;
			}
		}
		file_put_contents($path, $content);
	}
	function parseEnv($env)
	{
		$lines = explode("\n", $env);
		$res = [];
		foreach ($lines as $line) {
			$line = trim($line);
			if (strpos($line, "#") !== false) {
				continue;
			}
			if (strpos($line, "=") === false) {
				continue;
			}
			$parse = explode("=", $line);
			$res[trim(@$parse[0])] = trim(@$parse[1]);
		}
		return $res;
	}
	function processCmd($command, $getOutput = false)
	{
		$filesystem = new Filesystem();
		$sh_tmp = $filesystem->tempnam("/tmp/", "ssh");

		$filesystem->put($sh_tmp, is_array($command) ? implode("\n", $command) : $command);
		$filesystem->chmod($sh_tmp, 0755);

		$process = new SymfonyProcess(["sh", $sh_tmp]);
		$process->setTimeout(18000);

		if ($getOutput) {
			$process->run();
			return $process->getOutput();
		}

		$process->start();
		$pid = $process->getPid();
		$this->info("PID : " . $pid);

		$process->wait(function ($type, $buffer) {
			if (SymfonyProcess::ERR === $type) {
				echo trim($buffer) . "\n";
			} else {
				echo trim($buffer) . "\n";
			}
		});
		unset($sh_tmp);
	}
}
