<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\HydraBundle;

use Doctrine\Common\Annotations\Reader;
use ML\HydraBundle\Mapping\Operation;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The DocumentationGenerator
 */
class DocumentationGenerator
{
    const OPERATION_ANNOTATION = 'ML\\HydraBundle\\Mapping\\Operation';

    const COLLECTION_ANNOTATION = 'ML\\HydraBundle\\Mapping\\Collection';

    /**
     * @var \Symfony\Component\DependencyInjection\ContainerInterface
     */
    private $container;

    /**
     * @var \Symfony\Component\Routing\RouterInterface
     */
    private $router;

    /**
     * @var \Doctrine\Common\Annotations\Reader
     */
    private $reader;

    /**
     * Constructor
     *
     * @param ContainerInterface $container The service container.
     * @param RouterInterface    $router    The router.
     * @param Reader             $reader    The annotation reader.
     */
    public function __construct(ContainerInterface $container, RouterInterface $router, Reader $reader)
    {
        $this->container = $container;
        $this->router    = $router;
        $this->reader    = $reader;
    }

    /**
     * Gets the documentation
     *
     * @return array
     */
    public function getDocumentation()
    {
        $array = array();
        $resources = array();
        $documentation = array();
        $documentation['rels'] = array();
        $documentation['types2document'] = array();
        $documentation['vocab_base'] = $this->router->generate('hydra_vocab');
        $documentation['vocab_base_abs'] = $this->router->generate('hydra_vocab', array(), true);

        $this->documentType($documentation, 'ML\HydraBundle\Collection\Collection');

        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            if ($method = $this->getReflectionMethod($route->getDefault('_controller'))) {
                if ($annotation = $this->getAnnotation($method, self::OPERATION_ANNOTATION)) {
                    $this->documentRoute($documentation, $name, $route, $annotation, $method);
                }
            }
        }
        while (count($documentation['types2document']) > 0)
        {
            $type = array_pop($documentation['types2document']);
            $this->documentType($documentation, $type);
        }

        unset($documentation['types2document']);

        return $documentation;
    }

    /**
     * Returns the ReflectionMethod for the given controller string.
     *
     * @param string $controller
     * @return \ReflectionMethod|null
     */
    public function getReflectionMethod($controller)
    {
        if (preg_match('#(.+)::([\w]+)#', $controller, $matches)) {
            $class = $matches[1];
            $method = $matches[2];
        } elseif (preg_match('#(.+):([\w]+)#', $controller, $matches)) {
            $controller = $matches[1];
            $method = $matches[2];
            if ($this->container->has($controller)) {
                $this->container->enterScope('request');
                $this->container->set('request', new Request());
                $class = get_class($this->container->get($controller));
                $this->container->leaveScope('request');
            }
        }

        if (isset($class) && isset($method)) {
            try {
                return new \ReflectionMethod($class, $method);
            } catch (\ReflectionException $e) {
            }
        }

        return null;
    }

    /**
     * Returns a new Documentation instance with more data.
     *
     * @param array             $documentation
     * @param string            $routeName
     * @param Route             $route
     * @param Operation         $annotation
     * @param \ReflectionMethod $method
     */
    protected function documentRoute(&$documentation, $routeName, Route $route, Operation $annotation, \ReflectionMethod $method)
    {
        $doc = $this->getDocBlockText($method);

        if (null !== $annotation->expect) {
             $documentation['types2document'][] = $annotation->expect;
        }
        $doc['expect'] = $annotation->expect;
        $doc['status_codes'] = $annotation->status_codes;

        $doc['return'] = $this->getType($method);


        if (('array' === $doc['return']['type']) || ('HydraCollection' === $doc['return']['type'])) {
            $doc['return']['type'] = 'HydraCollection';
        }

        if ($doc['return']['type'] && !static::isPrimitiveType($doc['return']['type'])) {
                 $documentation['types2document'][] = $doc['return']['type'];
        }
        if (@$doc['return']['array_type'] && !static::isPrimitiveType($doc['return']['array_type'])) {
             $documentation['types2document'][] = $doc['return']['array_type'];
        }

        // method/IRI
        $doc['method'] = $route->getRequirement('_method') ?: 'ANY';
        $doc['iri'] = $route->getPattern();
        $doc['variables'] = $route->compile()->getVariables();
        $doc['defaults'] = $route->getDefaults();
        unset($doc['defaults']['_controller']);

        $documentation['routes'][$routeName] = $doc;
    }

    protected function getDocBlockText(\Reflector $element)
    {
        $text = $element->getDocComment();

        // store @var annotations if available (just one line supported)
        preg_match('/@var\s+([^\s]+)((?:[ ]+)[^\r\n]*)?/', $text, $var);

        // then remove annotations
        $text = preg_replace('/^\s+\* @[\w0-9]+.*/msi', '', $text);

        // let's clean the doc block
        $text = str_replace('/**', '', $text);
        $text = str_replace('*/', '', $text);
        $text = str_replace('*', '', $text);
        $text = str_replace("\r", '', trim($text));
        $text = preg_replace("#^\n[ \t]+[*]?#i", "\n", trim($text));
        $text = preg_replace("#[\t ]+#i", ' ', trim($text));
        $text = str_replace("\"", "\\\"", $text);

        // let's clean the doc block
        // $text = str_replace('/**', '', $text);
        // $text = str_replace('*/', '', $text);
        // $text = preg_replace('/^\s*\* ?/m', '', $text);

        $parts = explode("\n", $text, 2);
        $parts[0] = trim($parts[0]);
        $parts[1] = isset($parts[1]) ? trim($parts[1]) : '';

        if (('' === $parts[0]) && isset($var[2])) {
            $parts[0] = trim($var[2]);
        }

        if ('' !== $parts[1]) {
            $parts[1] = preg_replace("#\s*\n\s*\n\s*#", '@@KEEPPARAGRAPHS@@', $parts[1]);
            $parts[1] = preg_replace("#\s*\n\s*#", ' ', $parts[1]);
            $parts[1] = str_replace('@@KEEPPARAGRAPHS@@', "\n", $parts[1]);
        }

        return array('title' => $parts[0], 'description' => $parts[1]);
    }

    /**
     * Documents a type
     *
     * @param  array  $documentation The documentation.
     * @param  string $class         The type to document.
     * @param  string $group         The serialization group to use.
     */
    protected function documentType(&$documentation, $class, $group = null)
    {
        if (self::isPrimitiveType($class)) {
            return;
        }

        $idAnnot = 'ML\\HydraBundle\\Mapping\\Id';
        $exposeAnnot = 'ML\\HydraBundle\\Mapping\\Expose';

        $class = new \ReflectionClass($class);
        $exposeClassAs = $class->getShortName();

        if (null === ($annotation = $this->getAnnotation($class, $exposeAnnot))) {
            // TODO Improve this
            throw new \Exception($class->name . ' is directly or indirectly exposed but not annotated accordingly.');
        } else {
            if ($annotation->as) {
                $exposeClassAs = $annotation->as;
            }
        }

        if (isset($documentation['types'][$exposeClassAs])) {
            if ($class->name !== $documentation['types'][$exposeClassAs]['class']) {
                throw new \Exception(sprintf('The classes "%s" and "%s" have the same name (the namespace is ignored).',
                    $class->name,
                    $documentation['types'][$exposeClassAs]['class'])
                );
            }

            return;
        }

        $result = array();
        $result += $this->getDocBlockText($class);
        $result['class'] = $class->name;
        $result['properties'] = array();

        if (null !== ($annotation = $this->getAnnotation($class, $idAnnot))) {
            $variables = $annotation->variables;


            // TODO Handle all other possibilities

            $result['properties']['@id'] = array(
                'element' => null,
                'original_type' => null,
                'type' => '@id',
                'title' => 'The entity\'s IRI.',
                'description' => '',
                'route' => $annotation->route,   // TODO Need to check if GET allowed?
                'readonly' => true,
                'writeonly' => false,
                'operations' => array()
            );

            // TODO Check that the IRI template can be filled!?
        }

        $this->documentOperations($class, $result, $documentation);

        $elements = array_merge($class->getProperties(), $class->getMethods());

        foreach ($elements as $element) {
            if (null === ($annotation = $this->getAnnotation($element, $exposeAnnot))) {
                continue;
            }

            $definition = array();
            $definition['element'] = $element->name;
            $definition += $this->getType($element);

            $exposeAs = $element->name;
            if ($annotation->as) {
                $exposeAs = $annotation->as;
            }


            $definition['readonly'] = $annotation->readonly;
            $definition['writeonly'] = $annotation->writeonly;
            $definition += $this->getDocBlockText($element);

            if (null !== ($collection = $this->getAnnotation($element, self::COLLECTION_ANNOTATION))) {
                $collection = $collection->route;

                if ('HydraCollection' !== $documentation['routes'][$collection]['return']['type']) {
                    // TODO Improve this
                    var_dump($documentation['routes'][$collection]);
                    throw new \Exception(sprintf('"%s" in class "%s" is annotated as collection using the route "%s". The route, however, doesn\'t return a collection',
                        $element->name, $class->name, $collection));
                }

                if ((null !== $definition['type']) && ('array' !== $definition['type'])) {
                    // TODO Improve this
                    throw new \Exception($element->name . ' is being converted to a collection, it\'s return value must therefore be an array');
                }

                $definition['type'] = 'HydraCollection';
                $definition['array_type'] = $documentation['routes'][$collection]['return']['array_type'];
                // TODO Check that the IRI template can be filled!?
                $definition['route'] = $collection;
            }
            $definition['collection'] = $collection;

            $this->documentOperations($element, $definition, $documentation);

            if (isset($result['properties'][$exposeAs])) {
                // TODO Improve this!
                throw new \Exception(sprintf('Both "%s" and "%s" are being exposed as "%s" in class "%s"',
                    $result['properties'][$exposeAs]['element'], $element->name, $exposeAs, $class->name));
            }
            $result['properties'][$exposeAs] = $definition;

            // To avoid deep recursions
            if ($definition['type'] && !static::isPrimitiveType($definition['type'])) {
                 $documentation['types2document'][] = $definition['type'];
            }
            if (@$definition['array_type'] && !static::isPrimitiveType($definition['array_type'])) {
                 $documentation['types2document'][] = $definition['array_type'];
            }
        }

        $documentation['types'][$exposeClassAs] = $result;
    }

    /**
     * Document the operations associated to an element
     *
     * @param \Reflector $element  The element being documented.
     * @param array $definition    The element's definition
     * @param array $documentation The so far generated documentation.
     */
    private function documentOperations(\Reflector $element, &$definition, $documentation)
    {
        $operationsAnnot = 'ML\\HydraBundle\\Mapping\\Operations';
        if (null !== ($operations = $this->getAnnotation($element, $operationsAnnot))) {
            $operations = $operations->operations;

            $expectedIriPattern = isset($definition['route'])
                ? $documentation['routes'][$definition['route']]['iri']
                : $documentation['routes'][$operations[0]]['iri'];

            foreach ($operations as $operation) {
                if ($documentation['routes'][$operation]['iri'] !== $expectedIriPattern) {
                    if ($definition['route']) {
                        throw new \Exception(sprintf('The operations (routes: %s) associated with "%s" in "%s" don\'t use the properties IRI pattern "%s".',
                            implode(', ', $operations), $element->name, $class->name, $expectedIriPattern));
                    }
                    throw new \Exception(sprintf('The operations (routes: %s) associated with "%s" in "%s" don\'t use the same IRI pattern.',
                            implode(', ', $operations), $element->name, $class->name));
                }
            }

            if ($element instanceof \ReflectionClass) {
                // TODO Check that the IRI template can be filled!?
                if (false === isset($definition['route'])) {
                    $definition['route'] = $operations[0];
                }
            } elseif ('HydraCollection' !== $definition['type']) {
                if ('array' !== $definition['type']) {
                    throw new \Exception(sprintf('"%s" in "%s" specifies operations but it\'s return type is "%s" instead of array or HydraCollection.',
                        $element->name, $class->name, $definition['type']));
                } else {
                    $definition['type'] = '@id';
                    // TODO Check that the IRI template can be filled!?
                    if (false === isset($definition['route'])) {
                        $definition['route'] = $operations[0];
                    }
                }
            }
        } else {
            $operations = array();
        }

        $definition['operations'] = $operations;
    }

    /**
     * Get the annotation of an element
     *
     * @param  \Reflector $element    The element whose annotation should be
     *                                retrieved
     * @param  string     $annotation The annotation class to retrieve
     *
     * @return object|null The annotation or null if the annotation doesn't
     *                     exist on that object.
     */
    private function getAnnotation(\Reflector $element, $annotation)
    {
        if ($element instanceof \ReflectionClass) {
            return $this->reader->getClassAnnotation($element, $annotation);
        } elseif ($element instanceof \ReflectionMethod) {
            return $this->reader->getMethodAnnotation($element, $annotation);
        } elseif ($element instanceof \ReflectionProperty) {
            return $this->reader->getPropertyAnnotation($element, $annotation);
        }

        return null;
    }

    public function getType(\Reflector $element)
    {
        $type = ($element instanceof \ReflectionMethod)
            ? $this->getReturnAnnotation($element)
            : $this->getVarAnnotation($element);

        $result = array('type' => $type, 'original_type' => $type);

        if (null === $type) {
            return $result;
        }

        // Checks if the property has @var array<type> or type[] annotation
        if ($type && false !== $pos = strpos($type, '<')) {
            $result['array_type'] = substr($type, $pos + 1, -1);
            $result['type'] = 'array'; //substr($type, 0, $pos);

            // TODO Check this
            // if (isset(self::$typeMap[$arrayType])) {
            //     $arrayType = self::$typeMap[$arrayType];
            // }
        } elseif ('[]' === substr($type, -2)) {
            $result['array_type'] = substr($type, 0, -2);
            $result['type'] = 'array';
        }

        return $result;
    }

    /**
     * Extracts the type of a property using the @var annotation
     *
     * @param  \ReflectionProperty $property The property.
     * @return array The property's type.
     */
    private function getVarAnnotation(\ReflectionProperty $property)
    {
        $result = array();
        // Adapted from Doctrine\Common\Annotations\DocParser::collectAnnotationMetadata()

        // checks if the property has @var annotation
        if ((false !== $propertyComment = $property->getDocComment())
            && false !== strpos($propertyComment, '@var')
            && preg_match('/@var\s+([^\s]+)((?:[ ]+)[^\r\n]*)?/', $propertyComment, $matches)) {   // TODO Fix regex (line end)
            // literal type declaration
            $value = $matches[1];

            return $value;
        }

        return null;
    }

    private function getReturnAnnotation(\ReflectionMethod $method)
    {
        $result = array();
        // Adapted from Doctrine\Common\Annotations\DocParser::collectAnnotationMetadata()

        // checks if the property has @return annotation
        if ((false !== $methodComment = $method->getDocComment())
            && false !== strpos($methodComment, '@return')
            && preg_match('/@return\s+([^\s]+)((?:[ ]+)[^\r\n]*)?/', $methodComment, $matches)) {   // TODO Fix regex (line end)
            // literal type declaration
            $value = $matches[1];

            return $value;
        }

        return null;
    }

    /**
     * Checks whether the passed type is a primitive type
     *
     * @param  string  $type The type.
     *
     * @return boolean Return true if it is a primitive type, otherwise false.
     */
    private static function isPrimitiveType($type)
    {
        // TODO Remove HydraCollection to document it as soon as it exists
        return in_array($type, array('array', 'string', 'float', 'double', 'boolean', 'bool', 'integer', '@id', 'HydraCollection', 'void'));
    }
}
