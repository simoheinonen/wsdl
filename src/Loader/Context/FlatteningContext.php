<?php
declare(strict_types=1);

namespace Soap\Wsdl\Loader\Context;

use DOMElement;
use Soap\Wsdl\Loader\WsdlLoader;
use Soap\Wsdl\Xml\Configurator\FlattenTypes;
use Soap\Wsdl\Xml\Flattener;
use Soap\Xml\Xpath\WsdlPreset;
use VeeWee\Xml\Dom\Document;
use VeeWee\Xml\Exception\RuntimeException;
use function VeeWee\Xml\Dom\Mapper\xml_string;

final class FlatteningContext
{
    /**
     * XSD import catalog of location => raw (not flattened) xml
     *
     * @var array<string, string>
     */
    private $catalog = [];

    /**
     * Indicator for preventing circular xsd:includes and redefines
     *
     * @var array<string, true>
     */
    private $flattening = [];

    public static function forWsdl(
        string $location,
        Document $wsdl,
        WsdlLoader $loader,
    ): self {
        $new = new self($wsdl, $loader);
        $new->catalog[$location] = $wsdl->map(xml_string());

        return $new;
    }

    private function __construct(
        private Document $wsdl,
        private WsdlLoader $loader
    ) {
    }

    /**
     * This function can be used to detect if the context knows about a part of the WSDL.
     * It knows about a part from the moment that the raw XML version has been loaded once,
     * even if the flattening process is still in an underlying import / include.
     */
    public function knowsAboutPart(string $location): bool
    {
        return array_key_exists($location, $this->catalog);
    }

    /**
     * xsd:include and xsd:redefine should always be included in the schema that triggers the include.
     * This function will return a flattened version of the include.
     * Nested includes will be resolved.
     */
    public function include(string $location): ?string
    {
        // Prevent circular includes:
        if ($this->flattening[$location] ?? false) {
            return null;
        }

        return $this->loadFlattenedXml($location);
    }

    /**
     * xsd:import and wsdl:import's only need to occur once.
     * This function determines if an import should be done.
     *
     * It either returns null if the import already was done or the flattened XML if it still requires an import.
     */
    public function import(string $location): ?string
    {
        return $this->knowsAboutPart($location)
            ? null
            : $this->loadFlattenedXml($location);
    }

    /**
     * Returns the base WSDL document that can be worked on by flattener configurators.
     */
    public function wsdl(): Document
    {
        return $this->wsdl;
    }

    /**
     * This method searches for a single <wsdl:types /> tag
     * If no tag exists, it will create an empty one.
     * If multiple tags exist, it will merge those tags into one.
     *
     * @throws RuntimeException
     */
    public function types(): DOMElement
    {
        $doc = Document::fromUnsafeDocument($this->wsdl->toUnsafeDocument(), new FlattenTypes());
        $xpath = $doc->xpath(new WsdlPreset($doc));

        /** @var DOMElement $types */
        $types = $xpath->querySingle('//wsdl:types');

        return $types;
    }

    /**
     * This function will take care of the import catalog!
     * It will first load the raw xml from the remote source and store that internally.
     *
     * Next it will apply the XML flattening on the loaded xml and return the flattened string.
     * We keep track of all nested flattening locations that are in progress.
     * This way we can prevent circular includes as well.
     */
    private function loadFlattenedXml(string $location): string
    {
        if (!array_key_exists($location, $this->catalog)) {
            $this->catalog[$location] = ($this->loader)($location);
        }

        $document = Document::fromXmlString($this->catalog[$location]);

        $this->flattening[$location] = true;
        $result = (new Flattener())($location, $document, $this);
        unset($this->flattening[$location]);

        return $result;
    }
}
