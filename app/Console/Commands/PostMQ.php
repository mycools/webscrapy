<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\PostMQJob;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Throwable;
use Illuminate\Support\Facades\Schema;
use App\Models\WebsiteList;
class PostMQ extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = "wisesight:postmq {--site=} {--debug}";

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = "Command description";

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
		$site = $this->option("site");
		$debug = $this->option("debug");
		if ($site) {
			$list = WebsiteList::where("db_prefix", $site)->first();
			return $this->sendData($list, $debug);
		}
		$lists = WebsiteList::whereNotNull("db_prefix")
			->where("service_status", "schedule")
			->get();
		foreach ($lists as $list) {
			if ($this->sendData($list, $debug) == 1) {
				return 1;
			}
		}
		return 0;
	}
	public function sendData($list, $debug = false)
	{
		$site = $list->db_prefix;
		$web = WebsiteList::where("db_prefix", $site)->firstOrfail();
		$list->update([
			"service_status" => "sending",
			"issue_detail" => "Sending data to Rabbit MQ.",
			"postmq_at" => now()->format("Y-m-d H:i:s"),
		]);
		$table = $site . "_contents";
		if (Schema::connection("scrapy")->hasTable($table) === false) {
			$this->warn($table . " not exists");
			return 1;
		}
		$this->info("PostMQ : " . $table);
		DB::connection("scrapy")
			->table($table)
            ->where("is_updated", "1")
			->orderBy("cid")
			->chunk(30, function ($articles) use ($table, $web, $debug, $list) {
				foreach ($articles as $article) {
					// $article->content = $this->textFilter($article->content);
					$this->info($web->domain . " | " . $article->create_date . " | " . $article->title . " | " . $article->permalink);

					// printf("%s\n", $article->content);
					PostMQJob::dispatch($table, $web, $list, $article, $debug);
				}
			});
		$list->update([
			"service_status" => "schedule",
			"issue_detail" => "Completed",
			"postmq_at" => now()->format("Y-m-d H:i:s"),
		]);

		$this->info("Done.");
		return 0;
	}
}
