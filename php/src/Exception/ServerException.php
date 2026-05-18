<?php
namespace ZealPHP\MongoDB\Exception;
class ServerException extends RuntimeException
{
    private array $labels = [];
    public function getLabels(): array { return $this->labels; }
    public function hasLabel(string $label): bool { return in_array($label, $this->labels); }
}
