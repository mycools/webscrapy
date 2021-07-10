<?php

namespace App\Jobs;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Queue\SerializesModels;
use App\WebsiteList;
use App\Services\Filesystem\Filesystem;
use App\Services\Scripts\Parser;
use App\Services\Scripts\Runner as Process;
use RuntimeException;

/**
 * Updates the git mirror for a WebsiteList.
 * This is not queued as DeployWebsiteList needs it to run in sequence.
 */
class UpdateGitMirror extends Job
{
    use SerializesModels, DispatchesJobs;

    /**
     * @var int
     */
    public $timeout = 0;

    /**
     * @var WebsiteList
     */
    private $WebsiteList;

    /**
     * UpdateGitMirror constructor.
     *
     * @param WebsiteList $WebsiteList
     */
    public function __construct(WebsiteList $WebsiteList)
    {
        $this->WebsiteList = $WebsiteList;
    }

    /**
     * Execute the job.
     *
     * @param Process    $process
     * @param Parser     $parser
     * @param Filesystem $filesystem
     */
    public function handle(Process $process, Parser $parser, Filesystem $filesystem)
    {
        $private_key = $filesystem->tempnam(storage_path('app/tmp/'), 'key');
        $filesystem->put($private_key, $this->WebsiteList->private_key);
        $filesystem->chmod($private_key, 0600);

        $wrapper = $parser->parseFile('tools.SSHWrapperScript', [
            'private_key' => $private_key,
        ]);

        $wrapper_file = $filesystem->tempnam(storage_path('app/tmp/'), 'ssh');
        $filesystem->put($wrapper_file, $wrapper);
        $filesystem->chmod($wrapper_file, 0755);

        $this->WebsiteList->is_mirroring = true;
        $this->WebsiteList->save();

        $process->setScript('tools.MirrorGitRepository', [
            'wrapper_file' => $wrapper_file,
            'mirror_path'  => $this->WebsiteList->mirrorPath(),
            'repository'   => $this->WebsiteList->repository,
        ])->run();

        $successful = $process->isSuccessful();

        $filesystem->delete([$wrapper_file, $private_key]);

        if ($successful) {
            $this->WebsiteList->last_mirrored = $this->WebsiteList->freshTimestamp();
        }

        $this->WebsiteList->is_mirroring  = false;
        $this->WebsiteList->save();

        if (!$successful) {
            throw new RuntimeException('Could not mirror repository - ' . $process->getErrorOutput());
        }

        $this->dispatch(new UpdateGitReferences($this->WebsiteList));
    }
}
