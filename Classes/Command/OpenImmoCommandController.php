<?php

namespace Ujamii\OpenImmo\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

/**
 * @Flow\Scope("singleton")
 */
class OpenImmoCommandController extends CommandController
{
    /**
     * Generates wrapper files (yaml and fusion) for the ujamii/openimmo API.
     *
     * @return void
     */
    public function generateCommand(): void
    {
        try {
            $generator = new NeosAdapterGenerator($this->output);
            $generator->generateNeosFiles();
        } catch (\Exception $e) {
            $this->output->outputLine("<error>{$e->getMessage()}</error>");
            $this->sendAndExit(1);
        }
    }

    /**
     * Imports OpenImmo data from configured directory.
     *
     * @return void
     */
    public function importCommand(): void
    {
        try {
            $importer = new OpenImmoImporter($this->output);
            $importer->importData();
        } catch (\Exception $e) {
            $this->output->outputLine("<error>{$e->getMessage()}</error>");
            $this->sendAndExit(1);
        }
    }

}
