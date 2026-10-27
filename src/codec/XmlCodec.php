<?php

/*
 *
 * Licensed under the Apache License, Version 2.0.
 *
 * You may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * @author Zwuiix
 * @link https://github.com/Zwuiix-cmd/store
 *
 */

declare(strict_types=1);

namespace zwuiix\store\codec;

use SimpleXMLElement;
use zwuiix\store\data\ValueParser;
use zwuiix\store\exception\StoreException;
use zwuiix\store\StoreCodecInterface;

use function array_key_exists;
use function array_keys;
use function count;
use function htmlspecialchars;
use function is_array;
use function is_bool;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function preg_replace;
use function range;
use function simplexml_load_string;
use function str_repeat;
use function trim;
use const ENT_QUOTES;
use const ENT_XML1;
use const LIBXML_NOBLANKS;
use const LIBXML_NONET;

/**
 * Encodes and decodes XML Store documents.
 */
final class XmlCodec implements StoreCodecInterface
{
	/**
	 * Decodes an XML document into a Store value tree.
	 *
	 * @param string      $contents   XML document.
	 * @param string|null $passphrase Unused by the XML codec.
	 * @return array<string, mixed> Decoded Store values.
	 * @throws StoreException       If XML support is unavailable or the document is invalid.
	 */
	public function decode(string $contents, ?string $passphrase = null) : array
	{
		$previous = libxml_use_internal_errors(true);
		$xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$xml instanceof SimpleXMLElement) {
			throw new StoreException('Invalid XML store.');
		}

		$value = $this->decodeNode($xml);
		if (!is_array($value)) {
			throw new StoreException('An XML store root must contain child elements.');
		}

		return $value;
	}

	/**
	 * Encodes Store values as an XML document.
	 *
	 * @param array<string, mixed> $data       Values to encode.
	 * @param string|null          $passphrase Unused by the XML codec.
	 * @return string               XML document.
	 * @throws StoreException       If XML support is unavailable or a value cannot be encoded.
	 */
	public function encode(array $data, ?string $passphrase = null) : string
	{
		return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<store>\n" . $this->encodeNode($data, 1) . "</store>\n";
	}

	private function decodeNode(SimpleXMLElement $node) : mixed
	{
		$attributes = $node->attributes();
		if ((string) ($attributes['type'] ?? '') === 'array') {
			return [];
		}

		$children = $node->children();
		if (count($children) === 0) {
			return ValueParser::parse(trim((string) $node));
		}

		$data = [];
		foreach ($children as $name => $child) {
			$value = $this->decodeNode($child);
			if (array_key_exists($name, $data)) {
				if (!is_array($data[$name]) || !$this->isList($data[$name])) {
					$data[$name] = [$data[$name]];
				}

				$data[$name][] = $value;
			} else {
				$data[$name] = $value;
			}
		}

		return $data;
	}

	private function encodeNode(array $data, int $level) : string
	{
		$output = '';
		$indent = str_repeat("\t", $level);
		foreach ($data as $key => $value) {
			$name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) $key) ?: 'value';
			if (is_array($value) && $value !== [] && $this->isList($value)) {
				foreach ($value as $item) {
					$output .= $this->encodeElement($name, $item, $level);
				}
			} else {
				$output .= $this->encodeElement($name, $value, $level);
			}
		}

		return $output;
	}

	private function encodeElement(string $name, mixed $value, int $level) : string
	{
		$indent = str_repeat("\t", $level);
		if (is_array($value)) {
			if ($value === []) {
				return $indent . '<' . $name . ' type="array"></' . $name . ">\n";
			}

			return $indent . '<' . $name . '>\n' . $this->encodeNode($value, $level + 1) . $indent . '</' . $name . ">\n";
		}

		if (is_bool($value)) {
			$value = $value ? 'true' : 'false';
		} elseif ($value === null) {
			$value = 'null';
		}

		return $indent . '<' . $name . '>' . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $name . ">\n";
	}

	private function isList(array $value) : bool
	{
		return $value === [] || array_keys($value) === range(0, count($value) - 1);
	}
}
