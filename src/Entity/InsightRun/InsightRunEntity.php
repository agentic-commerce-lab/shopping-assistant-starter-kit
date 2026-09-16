<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\InsightRun;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Swag\AssistantStarterKit\Entity\InsightFinding\InsightFindingCollection;

class InsightRunEntity extends Entity
{
    use EntityIdTrait;

    protected \DateTimeInterface $windowStart;

    protected \DateTimeInterface $windowEnd;

    /** @var array<string, int> */
    protected array $metrics = [];

    /** @var array<string, list<string>>|null */
    protected ?array $searchTerms = null;

    protected string $sampleSeed = '';

    protected ?string $judgeError = null;

    protected ?InsightFindingCollection $findings = null;

    public function getWindowStart(): \DateTimeInterface
    {
        return $this->windowStart;
    }

    public function setWindowStart(\DateTimeInterface $windowStart): void
    {
        $this->windowStart = $windowStart;
    }

    public function getWindowEnd(): \DateTimeInterface
    {
        return $this->windowEnd;
    }

    public function setWindowEnd(\DateTimeInterface $windowEnd): void
    {
        $this->windowEnd = $windowEnd;
    }

    /** @return array<string, int> */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /** @param array<string, int> $metrics */
    public function setMetrics(array $metrics): void
    {
        $this->metrics = $metrics;
    }

    /** @return array<string, list<string>>|null */
    public function getSearchTerms(): ?array
    {
        return $this->searchTerms;
    }

    /** @param array<string, list<string>>|null $searchTerms */
    public function setSearchTerms(?array $searchTerms): void
    {
        $this->searchTerms = $searchTerms;
    }

    public function getSampleSeed(): string
    {
        return $this->sampleSeed;
    }

    public function setSampleSeed(string $sampleSeed): void
    {
        $this->sampleSeed = $sampleSeed;
    }

    public function getJudgeError(): ?string
    {
        return $this->judgeError;
    }

    public function setJudgeError(?string $judgeError): void
    {
        $this->judgeError = $judgeError;
    }

    public function getFindings(): ?InsightFindingCollection
    {
        return $this->findings;
    }

    public function setFindings(?InsightFindingCollection $findings): void
    {
        $this->findings = $findings;
    }
}
