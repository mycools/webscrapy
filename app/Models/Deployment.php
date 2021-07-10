<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Deployment extends Model
{
    use HasFactory;



    const COMPLETED             = 0;
    const PENDING               = 1;
    const DEPLOYING             = 2;
    const FAILED                = 3;
    const COMPLETED_WITH_ERRORS = 4;
    const ABORTING              = 5;
    const ABORTED               = 6;
    const LOADING               = 'Loading';

    public static $currentDeployment = [];

    protected $fillable = [
        "committer",
        "committer_email",
        "commit",
        "website_id",
        "user_id",
        "reason",
        "branch",
        "is_webhook",
        "source",
        "build_url",
        "status",
        "started_at",
        "finished_at",
    ];
    protected $casts = [
        "started_at" => "datetime",
        "finished_at" => "datetime",
    ];
    public function getReleaseIdAttribute()
    {
        return $this->started_at->format('YmdHis');
    }
    public function abortQueued($id)
    {
        $deployments = Deployment::where('id', $id)
                                   ->whereIn('status', [Deployment::DEPLOYING, Deployment::PENDING])
                                   ->orderBy('started_at', 'DESC')
                                   ->get();

        foreach ($deployments as $deployment) {
            $deployment->status = Deployment::ABORTING;
            $deployment->save();

            $this->dispatch(new AbortDeployment($deployment));

            if ($deployment->is_webhook) {
                $deployment->delete();
            }
        }
    }
    /**
     * Determines whether the deployment is running.
     *
     * @return bool
     */
    public function isRunning()
    {
        return ($this->status === self::DEPLOYING);
    }

    /**
     * Determines whether the deployment is pending.
     *
     * @return bool
     */
    public function isPending()
    {
        return ($this->status === self::PENDING);
    }

    /**
     * Determines whether the deployment is successful.
     *
     * @return bool
     */
    public function isSuccessful()
    {
        return ($this->status === self::COMPLETED);
    }

    /**
     * Determines whether the deployment failed.
     *
     * @return bool
     */
    public function isFailed()
    {
        return ($this->status === self::FAILED);
    }

    /**
     * Determines whether the deployment is waiting to be aborted.
     *
     * @return bool
     */
    public function isAborting()
    {
        return ($this->status === self::ABORTING);
    }

    /**
     * Determines whether the deployment is aborted.
     *
     * @return bool
     */
    public function isAborted()
    {
        return ($this->status === self::ABORTED);
    }

    /**
     * Determines if the deployment is the latest deployment.
     *
     * @return bool
     */
    public function isCurrent()
    {
        if (!isset(self::$currentDeployment[$this->project_id])) {
            self::$currentDeployment[$this->project_id] = self::where('project_id', $this->project_id)
                                                              ->where('status', self::COMPLETED)
                                                              ->orderBy('id', 'desc')
                                                              ->first();
        }

        if (isset(self::$currentDeployment[$this->project_id]) &&
            self::$currentDeployment[$this->project_id]->id === $this->id
        ) {
            return true;
        }

        return false;
    }

    /**
     * Determines how long the deploy took.
     *
     * @return false|int False if the deploy is still running, otherwise the runtime in seconds
     */
    public function runtime()
    {
        if (!$this->finished_at) {
            return false;
        }

        return $this->started_at->diffInSeconds($this->finished_at);
    }

    /**
     * Gets the HTTP URL to the commit.
     *
     * @return string|false
     */
    public function getCommitUrlAttribute()
    {
        if ($this->commit !== self::LOADING) {
            $info = $this->project->accessDetails();
            if (isset($info['domain']) && isset($info['reference'])) {
                $path = 'commit';
                if (preg_match('/bitbucket/', $info['domain'])) {
                    $path = 'commits';
                }

                return 'http://' . $info['domain'] . '/' . $info['reference'] . '/' . $path . '/' . $this->commit;
            }
        }

        return false;
    }

    /**
     * Gets the short commit hash.
     *
     * @return string
     */
    public function getShortCommitAttribute()
    {
        if ($this->commit !== self::LOADING) {
            return substr($this->commit, 0, 7);
        }

        return $this->commit;
    }

    /**
     * Gets the HTTP URL to the branch.
     *
     * @return string|false
     *
     * @see Project::accessDetails()
     */
    public function getBranchUrlAttribute()
    {
        return $this->project->getBranchUrlAttribute($this->branch);
    }

    public function getRepositoryPathAttribute()
    {
        $info = $this->accessDetails();

        if (isset($info['reference'])) {
            return $info['reference'];
        }

        return false;
    }

    public function project()
    {
        return $this->hasOne(\App\Models\WebsiteList::class,'website_id','id');
    }
}
