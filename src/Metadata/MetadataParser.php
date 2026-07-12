<?php

declare(strict_types=1);

namespace FmsOData\Metadata;

use FmsOData\Spec\Errors\FMODataError;
use FmsOData\Spec\Errors\RequestRef;
use FmsOData\Spec\Metadata\EdmAction;
use FmsOData\Spec\Metadata\EdmEntitySet;
use FmsOData\Spec\Metadata\EdmEntityType;
use FmsOData\Spec\Metadata\EdmProperty;
use FmsOData\Spec\Metadata\FMServerVersion;
use FmsOData\Spec\Metadata\Metadata;
use FmsOData\Spec\Metadata\ODataMetadata;

final class MetadataParser
{
    public static function parse(string $xml): ODataMetadata
    {
        $trimmed = \trim($xml);
        if ($trimmed === '') {
            throw new FMODataError('Empty metadata XML', status: 0);
        }

        if (!\str_contains($trimmed, '<edmx:DataServices')
            && !\str_contains($trimmed, '<Schema')
            && !\str_contains($trimmed, '<edmx')) {
            throw new FMODataError('Malformed metadata XML: no DataServices or Schema element found', status: 0);
        }

        $namespace = self::getAttr($xml, 'Schema', 'Namespace') ?? '';
        $entityTypes = self::parseEntityTypes($xml);
        $entitySets = self::parseEntitySets($xml);
        $actions = self::parseActions($xml);
        $serverVersion = Metadata::parseServerVersion($xml);
        $versionRaw = $serverVersion !== null ? $serverVersion->raw : null;

        return new ODataMetadata(
            namespace: $namespace,
            entityTypes: $entityTypes,
            entitySets: $entitySets,
            actions: $actions,
            enumTypes: [],
            raw: $xml,
            productVersion: $versionRaw,
            serverVersion: $versionRaw,
        );
    }

    private static function getAttr(string $xml, string $tagName, string $attrName): ?string
    {
        $pattern = '/<' . \preg_quote($tagName, '/') . '\b[^>]*\b' . \preg_quote($attrName, '/') . '\s*=\s*"([^"]*)"/i';
        if (\preg_match($pattern, $xml, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function getAttrs(string $elementText): array
    {
        $attrs = [];
        $openingTag = $elementText;
        $gtPos = \strpos($elementText, '>');
        if ($gtPos !== false) {
            $openingTag = \substr($elementText, 0, $gtPos);
        }
        if (\preg_match_all('/(\w+)\s*=\s*"([^"]*)"/', $openingTag, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $attrs[$m[1]] = $m[2];
            }
        }

        return $attrs;
    }

    /**
     * @return list<string>
     */
    private static function findElements(string $xml, string $tagName): array
    {
        $elements = [];
        $pattern = '/<([\w]+:)?' . \preg_quote($tagName, '/') . '\b[^>]*?(\/?)>/i';

        if (!\preg_match_all($pattern, $xml, $matches, \PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches[0] as [$fullTag, $offset]) {
            $offset = (int) $offset;
            $tagText = $fullTag;
            if (\str_ends_with(\trim($tagText), '/>')) {
                $elements[] = $tagText;

                continue;
            }

            $closePattern = '/<\/([\w]+:)?' . \preg_quote($tagName, '/') . '\s*>/i';
            if (\preg_match($closePattern, $xml, $closeMatch, \PREG_OFFSET_CAPTURE, $offset + \strlen($tagText))) {
                $closeTag = $closeMatch[0][0];
                $closePos = $closeMatch[0][1];
                $fullElement = \substr($xml, $offset, $closePos + \strlen($closeTag) - $offset);
                $elements[] = $fullElement;
            } else {
                $elements[] = $tagText;
            }
        }

        return $elements;
    }

    private static function elementContent(string $xml, string $tagName): ?string
    {
        $pattern = '/<([\w]+:)?' . \preg_quote($tagName, '/') . '\b[^>]*>(.*?)<\/([\w]+:)?' . \preg_quote($tagName, '/') . '>/is';
        if (\preg_match($pattern, $xml, $m)) {
            return $m[2];
        }

        return null;
    }

    /**
     * @return list<EdmEntityType>
     */
    private static function parseEntityTypes(string $xml): array
    {
        $types = [];
        $elements = self::findElements($xml, 'EntityType');

        foreach ($elements as $element) {
            $name = self::getAttr($element, 'EntityType', 'Name');
            if ($name === null) {
                $attrs = self::getAttrs($element);
                $name = $attrs['Name'] ?? null;
            }
            if ($name === null) {
                continue;
            }

            $keys = self::parseKeys($element);
            $properties = self::parseProperties($element, $keys);

            $types[] = new EdmEntityType($name, $keys, $properties);
        }

        return $types;
    }

    /**
     * @return list<string>
     */
    private static function parseKeys(string $entityTypeXml): array
    {
        $keys = [];
        $keyContent = self::elementContent($entityTypeXml, 'Key');
        if ($keyContent === null) {
            return $keys;
        }

        if (\preg_match_all('/<PropertyRef\s+Name\s*=\s*"([^"]*)"\s*\/?>/i', $keyContent, $matches)) {
            foreach ($matches[1] as $name) {
                $keys[] = $name;
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<EdmProperty>
     */
    private static function parseProperties(string $entityTypeXml, array $keys): array
    {
        $properties = [];
        $elements = self::findElements($entityTypeXml, 'Property');

        foreach ($elements as $element) {
            $attrs = self::getAttrs($element);
            $name = $attrs['Name'] ?? '';
            $type = $attrs['Type'] ?? '';
            if ($name === '') {
                continue;
            }

            $nullable = isset($attrs['Nullable']) ? $attrs['Nullable'] === 'true' : null;
            $maxLength = null;
            if (isset($attrs['MaxLength']) && \ctype_digit($attrs['MaxLength'])) {
                $maxLength = (int) $attrs['MaxLength'];
            }

            $isKey = \in_array($name, $keys, true);

            $properties[] = new EdmProperty($name, $type, $nullable, $maxLength, $isKey);
        }

        return $properties;
    }

    /**
     * @return list<EdmEntitySet>
     */
    private static function parseEntitySets(string $xml): array
    {
        $sets = [];
        $elements = self::findElements($xml, 'EntitySet');

        foreach ($elements as $element) {
            $attrs = self::getAttrs($element);
            $name = $attrs['Name'] ?? '';
            $entityType = $attrs['EntityType'] ?? '';
            if ($name === '') {
                continue;
            }
            $sets[] = new EdmEntitySet($name, $entityType);
        }

        return $sets;
    }

    /**
     * @return list<EdmAction>
     */
    private static function parseActions(string $xml): array
    {
        $actions = [];
        $elements = self::findElements($xml, 'Action');

        foreach ($elements as $element) {
            $attrs = self::getAttrs($element);
            $name = $attrs['Name'] ?? '';
            if ($name === '') {
                continue;
            }

            $isBound = isset($attrs['IsBound']) && $attrs['IsBound'] === 'true';
            $scriptId = null;
            $parameterType = null;
            $returnType = null;

            $paramElements = self::findElements($element, 'Parameter');
            foreach ($paramElements as $paramElement) {
                $paramAttrs = self::getAttrs($paramElement);
                $paramName = $paramAttrs['Name'] ?? '';
                $paramTypeVal = $paramAttrs['Type'] ?? '';

                if ($paramName === 'parameter') {
                    $parameterType = $paramTypeVal;
                } elseif ($paramName === 'scriptResult') {
                    $returnType = $paramTypeVal;
                }
            }

            if (isset($attrs['ScriptID'])) {
                $scriptId = $attrs['ScriptID'];
            }

            $actions[] = new EdmAction($name, $isBound, $scriptId, $parameterType, $returnType);
        }

        return $actions;
    }
}
