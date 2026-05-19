<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * Interface for BSON Regex type.
 */
interface RegexInterface
{
    /**
     * Returns the Regex's pattern.
     */
    public function getPattern(): string;

    /**
     * Returns the Regex's flags.
     */
    public function getFlags(): string;

    /**
     * Returns the string representation of this Regex.
     */
    public function __toString(): string;
}
