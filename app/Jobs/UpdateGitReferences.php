<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\WebsiteList;
use App\Repositories\Contracts\RefRepositoryInterface;
use App\Services\Scripts\Runner as Process;

/**
 * Updates the list of tags and branches in a WebsiteList.
 */
class UpdateGitReferences extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    /**
     * @var WebsiteList
     */
    private $WebsiteList;

    /**
     * UpdateGitReferences constructor.
     *
     * @param WebsiteList $WebsiteList
     */
    public function __construct(WebsiteList $WebsiteList)
    {
        $this->WebsiteList = $WebsiteList;
    }

    /**
     * Execute the job.
     * @param Process                $process
     * @param RefRepositoryInterface $repository
     */
    public function handle(Process $process, RefRepositoryInterface $repository)
    {
        $mirror_dir = $this->WebsiteList->mirrorPath();

        $this->WebsiteList->refs()->delete();

        foreach (['tag', 'branch'] as $ref) {
            $process->setScript('tools.ListGitReferences', [
                'mirror_path'   => $mirror_dir,
                'git_reference' => $ref,
            ])->run();

            if ($process->isSuccessful()) {
                foreach (explode(PHP_EOL, trim($process->getOutput())) as $reference) {
                    $reference = trim($reference);

                    if (empty($reference)) {
                        continue;
                    }

                    if (starts_with($reference, '*')) {
                        $reference = trim(substr($reference, 1));
                    }

                    $repository->create([
                        'name'       => $reference,
                        'website_id' => $this->WebsiteList->id,
                        'is_tag'     => ($ref === 'tag'),
                    ]);
                }
            }
        }
    }
}
