<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Throwable;
use Illuminate\Support\Facades\Schema;
use App\Models\WebsiteList;
use \Log;
class PostMQJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	/**
	 * Create a new job instance.
	 *
	 * @return void
	 */
	private $table;
	private $web;
	private $debug;
	private $weblist;
	private $article;
	public function __construct($table, $web, WebsiteList $weblist, $article, $debug)
	{
		$this->table = $table;
		$this->web = $web;
		$this->debug = $debug;
		$this->weblist = $weblist;
		$this->article = $article;
	}

	/**
	 * Execute the job.
	 *
	 * @return void
	 */
	public function handle()
	{
		$table = $this->table;
		$web = $this->web;
		$debug = $this->debug;
		$list = $this->weblist;
		$article = $this->article;
		if (trim($article->content) && trim($article->title)) {
			if (strpos(trim($article->content), trim($article->title)) === 0) {
				//$article->content = mb_substr(trim($article->content), strlen(trim($article->title)));
			}
			$article->content = $this->textFilter($article->content);
		}
        $article->content = $this->textFilter($article->content);
		$article->title = $this->textFilter($article->title);
		if (is_numeric($article->title)) {
			Log::debug("[" . $article->website_domain . "]" . $article->title . " is not of type 'string");
			return 0;
		}
		$article->title = str_replace('"', " ", $article->title);

		$pictures = json_decode(trim($article->picture_urls) ? $article->picture_urls : "[]", true);
		$picture_urls = [];
		if (is_array($pictures)) {
			foreach ($pictures as $url) {
				if (strpos($url, "//") === 0) {
					$picture_urls[] = "https:" . trim($url);
				} elseif (strpos($url, "http") !== 0) {
					$picture_urls[] = $article->website_domain . trim($url);
				} else {
					$picture_urls[] = trim($url);
				}
			}
		}

		if (strtotime($article->create_date)) {
			$article->create_date = date("Y-m-d H:i:s", strtotime($article->create_date));
		} else {
			if (@date("Y-m-d", $article->create_date) !== "1970-01-01") {
				$article->create_date = @date("Y-m-d H:i:s", $article->create_date);
			} else {
				$article->create_date = now()->format("Y-m-d H:i:s");
			}
		}
        // print($article->content . "\n");
		$content = [
			"uid" => (string) Str::uuid(),
			"title" => $article->title,
			"text" => trim($article->content),
			"date" => Carbon::parse($article->create_date)->toISOString(),
			"permalink" => $article->permalink,

			// "website_url" =>  $article->permalink,
			"website_url" => $article->website_domain,
			"website_domain" => $web->domain,
			"message_type" => $article->message_type,
			"website_type" => $article->website_type,
			"author" => $article->author,
			"comment_id" => null,
			"view_count" => $article->view_count,
			"comment_count" => $article->comment_count,
			"tags" => json_decode(trim($article->tags) ? $article->tags : "[]"),
			"picture_urls" => array_values($picture_urls),
			"timezone" => $web->timezone,
		];
		$payload = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
		if ($debug) {
		} else {
			$rest = \Queue::connection("datamq")->pushRaw($payload, "ScrapingPosts");
			// DB::connection("scrapy")
			// 	->table($table)
			// 	->where("cid", $article->cid)
			// 	->update([
			// 		"is_transfer" => 1,
			// 		"is_updated" => 0,
			// 	]);
		}
		// $list->update([
		// 	"service_status" => "sending",
		// 	"issue_detail" => "Send data " . $article->create_date . " " . $content["title"],
		// 	"postmq_at" => now()->format("Y-m-d H:i:s"),
		// ]);
		Log::debug("[" . $web->domain . "] " . $article->create_date . " | " . $article->title);
	}

	public function textFilter($input_lines)
	{
		$input_lines = trim($input_lines);

		$input_lines = trim(strip_tags($input_lines));
		$input_lines = str_replace('"', " ", $input_lines);
		$input_lines = preg_replace("/\s+/", " ", $input_lines);
		$input_lines = str_replace('"', " ", $input_lines);
		$input_lines = str_replace("\xc2\xa0", " ", $input_lines);
		$input_lines = str_replace("&nbsp;", " ", $input_lines);
		$input_lines = preg_replace("/&#?[a-z0-9]{2,8};/i", " ", $input_lines);

		$input_lines = str_replace("googletag.cmd.push(function(){googletag.display('div-gpt-ad-8668011-5');});ย้อนกลับ", "", $input_lines);
		$input_lines = preg_replace(
			'/(?:(?:31(\/|-|\.|\ |)(?:0?[13578]|1[02]|(?:พ\.ค\.|Jan|Mar|May|Jul|Aug|Oct|Dec)))\1|(?:(?:29|30)(\/|-|\.|\ |)(?:0?[1,3-9]|1[0-2]|(?:พ\.ค\.|Jan|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec))\2))(?:(?:1[6-9]|[2-9]\d)?\d{2})$|^(?:29(\/|-|\.|\ |)(?:0?2|(?:Feb))\3(?:(?:(?:1[6-9]|[2-9]\d)?(?:0[48]|[2468][048]|[13579][26])|(?:(?:16|[2468][048]|[3579][26])00))))$|^(?:0?[1-9]|1\d|2[0-8])(\/|-|\.|\ ||\s+)(?:(?:0?[1-9]|(?:พ\.ค\.|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep))|(?:1[0-2]|(?:Oct|Nov|Dec)))\4(?:(?:1[6-9]|[2-9]\d)?\d{2})(\s+\| เข้าชม \: (\d+))/',
			"",
			$input_lines
		);
		$input_lines = preg_replace("/\s+/", " ", $input_lines);
		$input_lines = preg_split("/(\>\>\s+อ่าน)/", $input_lines)[0];
		$input_lines = str_replace("\t", " ", $input_lines);
		$input_lines = str_replace("---", "", $input_lines);
		return trim($input_lines);
	}
}
