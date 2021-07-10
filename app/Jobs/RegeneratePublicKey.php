<?php

namespace App\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Project;
use App\Services\Filesystem\Filesystem;
use App\Services\Scripts\Runner as Process;
use RuntimeException;

/**
 * Job to regenerate the public SSH key.
 */
class RegeneratePublicKey extends Job
{
    use Dispatchable, SerializesModels;

    /**
     * @var Project
     */
    private $project;

    /**
     * Create a new job instance.
     *
     * @param Project $project
     */
    public function __construct(Project $project)
    {
        $this->project = $project;
    }

    /**
     * Execute the job.
     *
     * @param Filesystem $filesystem
     * @param Process    $process
     *
     * @throws RuntimeException
     */
    public function handle(Filesystem $filesystem, Process $process)
    {
        $private_key_file = $filesystem->tempnam(storage_path('app/tmp'), 'key');
        $public_key_file  = $private_key_file . '.pub';

        $filesystem->put($private_key_file, $this->project->private_key);
        $filesystem->chmod($private_key_file, 0600);

        $process->setScript('tools.RegeneratePublicSSHKey', [
            'key_file' => $private_key_file,
            'project'  => $this->project->name,
        ])->run();

        $files = [$private_key_file, $public_key_file];

        if (!$process->isSuccessful()) {
            $filesystem->delete($files);
            throw new RuntimeException($process->getErrorOutput());
        }

        $this->project->public_key = $filesystem->get($public_key_file);

        $filesystem->delete($files);
    }
}
