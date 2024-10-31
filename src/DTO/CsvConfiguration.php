<?php

namespace Topdata\TopdataWebserviceConnectorSW6\DTO;

/**
 * Configuration for CSV import
 */
class CsvConfiguration
{



    /**
     * @param array<string, int|null> $columnMapping
     */
    public function __construct(
        private readonly string $delimiter,
        private readonly string $enclosure,
        private readonly int    $startLine,
        private readonly ?int   $endLine,
        private readonly array  $columnMapping
    )
    {
    }

    public function getDelimiter(): string
    {
        return $this->delimiter;
    }

    public function getEnclosure(): string
    {
        return $this->enclosure;
    }

    public function getStartLine(): int
    {
        return $this->startLine;
    }

    public function getEndLine(): ?int
    {
        return $this->endLine;
    }

    public function getColumnMapping(): array
    {
        return $this->columnMapping;
    }
}