<?php

namespace Ujamii\OpenImmo\Command;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Ujamii\OpenImmo\Service\ContentHelper;

/**
 * @Flow\Scope("singleton")
 */
class OpenImmoCommandController extends CommandController
{

    /**
     * @var ContentHelper
     * @Flow\Inject
     */
    protected $contentHelper;

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

    /**
     * Removes all nodes belonging to this package from the content repository.
     *
     * @throws \Neos\Eel\Exception
     */
    public function clearCommand(): void
    {
        /* @var array<NodeInterface> $allImmoNodes */
        $allImmoNodes = $this->contentHelper->findNodesByNodeType(OpenImmoImporter::OPEN_IMMO_DOCUMENT_IMMOBILIE);
        foreach ($allImmoNodes as $immoNode) {
            $immoNode->remove();
        }
    }

}
