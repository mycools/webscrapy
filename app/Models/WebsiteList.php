<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class WebsiteList extends Model
{
	use HasFactory, SoftDeletes;
	protected $fillable = [
		"name",
		"domain",
		"url",
		"timezone",
		"sub_channel",
		"source",
		"difficulty",
		"priority",
		"remark",
		"issue_detail",
		"status",
		"service_status",
		"service_at",
		"postmq_at",
		"seen_at",
		"git_webhook_url",
		"git_repo",
		"git_branch",
		"last_mirrored",
		"db_prefix",
		"sql",
		"command_install",
		"command_before_new",
		"command_run_new",
		"command_after_new",
		"command_before_watch",
		"command_run_watch",
		"command_after_watch",
		"git_ssh_key",
	];
	protected $casts = [
		"service_at" => "date:Y-m-d H:i:s",
		"postmq_at" => "date:Y-m-d H:i:s",
		"seen_at" => "date:Y-m-d H:i:s",
	];
	protected $hidden = ["git_ssh_key"];
	public static function boot()
	{
		parent::boot();
		static::created(function ($item) {
			$item->git_webhook_url = (string) Str::uuid();
			$item->save();
		});
		static::updated(function ($item) {
			if (!trim($item->git_webhook_url)) {
				$item->git_webhook_url = (string) Str::uuid();
				$item->save();
			}
		});
	}

	public function getBranchUrlAttribute($alternative = null)
	{
		$info = $this->accessDetails();

		if (isset($info["domain"]) && isset($info["reference"])) {
			$path = "tree";
			if (str_contains($info["domain"], "bitbucket")) {
				$path = "commits/branch";
			}

			if (!isset($info["scheme"]) || !Str::startsWith($info["scheme"], "http")) {
				$info["scheme"] = "http";
			}

			// Always serve github links over HTTPS
			if (Str::endsWith($info["domain"], "github.com")) {
				$info["scheme"] = "https";
			}

			$branch = is_null($alternative) ? $this->git_branch : $alternative;

			return $info["scheme"] . "://" . $info["domain"] . "/" . $info["reference"] . "/" . $path . "/" . $branch;
		}

		return false;
	}

	public function generateHash()
	{
		$this->attributes["hash"] = token(60);
	}

	/**
	 * Parses the repository URL to get the user, domain, port and path parts.
	 *
	 * @return array
	 */
	public function accessDetails()
	{
		$info = [];

		if (preg_match('#^(.+)://(.+)@(.+):([0-9]*)\/?(.+)\.git$#', $this->git_repo, $matches)) {
			$info["scheme"] = strtolower($matches[1]);
			$info["user"] = $matches[2];
			$info["domain"] = $matches[3];
			$info["port"] = $matches[4];
			$info["reference"] = $matches[5];
		} elseif (preg_match('#^(.+)@(.+):([0-9]*)\/?(.+)\.git$#', $this->git_repo, $matches)) {
			$info["scheme"] = "git";
			$info["user"] = $matches[1];
			$info["domain"] = $matches[2];
			$info["port"] = $matches[3];
			$info["reference"] = $matches[4];
		} elseif (preg_match("#^https?://#i", $this->git_repo)) {
			$data = parse_url($this->git_repo);

			if (!$data) {
				return $info;
			}

			$info["scheme"] = strtolower($data["scheme"]);
			$info["user"] = isset($data["user"]) ? $data["user"] : "";
			$info["domain"] = $data["host"];
			$info["port"] = isset($data["port"]) ? $data["port"] : "";
			$info["reference"] = substr($data["path"], 1, -4);
		}

		return $info;
	}

	/**
	 * Gets the repository path.
	 *
	 * @return string|false
	 *
	 * @see Project::accessDetails()
	 */
	public function getRepositoryPathAttribute()
	{
		$info = $this->accessDetails();

		if (isset($info["reference"])) {
			return $info["reference"];
		}

		return false;
	}

	/**
	 * Gets the HTTP URL to the repository.
	 *
	 * @return string|false
	 *
	 * @see Project::accessDetails()
	 */
	public function getRepositoryUrlAttribute()
	{
		$info = $this->accessDetails();

		if (isset($info["domain"]) && isset($info["reference"])) {
			if (!isset($info["scheme"]) || !Str::startsWith($info["scheme"], "http")) {
				$info["scheme"] = "http";
			}

			// Always serve github links over HTTPS
			if (Str::endsWith($info["domain"], "github.com")) {
				$info["scheme"] = "https";
			}
			if (Str::endsWith($info["domain"], "gitlab.com")) {
				$info["scheme"] = "https";
			}

			return $info["scheme"] . "://" . $info["domain"] . "/" . $info["reference"];
		}

		return false;
	}

	public function getIconAttribute()
	{
		$details = $this->accessDetails();

		if (isset($details["domain"])) {
			if (preg_match("/github\.com/", $details["domain"])) {
				return "fa-github";
			} elseif (preg_match("/gitlab\.com/", $details["domain"])) {
				return "fa-gitlab";
			} elseif (preg_match("/bitbucket/", $details["domain"])) {
				return "fa-bitbucket";
			} elseif (preg_match("/amazonaws\.com/", $details["domain"])) {
				return "fa-amazon";
			}
		}

		return "fa-git-square";
	}
	public function refs()
	{
		return $this->hasMany(\App\Models\Ref::class);
	}

	public function mirrorPath()
	{
		return storage_path("app/mirrors/" . preg_replace("/[^_\-.\-a-zA-Z0-9\s]/u", "_", $this->git_repo));
	}
}
