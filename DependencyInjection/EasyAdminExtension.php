<?php

/*
 * This file is part of the EasyAdminBundle.
 *
 * (c) Javier Eguiluz <javier.eguiluz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JavierEguiluz\Bundle\EasyAdminBundle\DependencyInjection;

use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Config\FileLocator;

class EasyAdminExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        // process bundle's configuration parameters
        $backendConfiguration = $this->processConfiguration(new Configuration(), $configs);
        $backendConfiguration['entities'] = $this->getEntitiesConfiguration($backendConfiguration['entities']);

        $container->setParameter('easyadmin.config', $backendConfiguration);

        // load bundle's services
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');
    }

    /**
     * Processes, normalizes and initializes the configuration of the entities
     * that are managed by the backend. Several configuration formats are allowed,
     * so this method normalizes them all.
     *
     * @param  array $entitiesConfiguration
     * @return array The full entity configuration
     */
    protected function getEntitiesConfiguration(array $entitiesConfiguration)
    {
        if (0 === count($entitiesConfiguration)) {
            return $entitiesConfiguration;
        }

        $configuration = $this->normalizeEntitiesConfiguration($entitiesConfiguration);
        $configuration = $this->processEntitiesConfiguration($configuration);
        $configuration = $this->ensureThatEntityNamesAreUnique($configuration);

        return $configuration;
    }

    /**
     * Transforms the two simple configuration formats into the full expanded
     * configuration. This allows to reuse the same method to process any of the
     * different configuration formats.
     *
     * These are the two simple formats allowed:
     *
     * # Config format #1: no custom entity label
     * easy_admin:
     *     entities:
     *         - AppBundle\Entity\User
     *
     * # Config format #2: simple config with custom entity label
     * easy_admin:
     *     entities:
     *         User: AppBundle\Entity\User
     *
     * And this is the full expanded configuration syntax generated by this method:
     *
     * # Config format #3: expanded entity configuration with 'class' parameter
     * easy_admin:
     *     entities:
     *         User:
     *             class: AppBundle\Entity\User
     *
     * @param  array $entitiesConfiguration The entity configuration in one of the simplified formats
     * @return array The normalized configuration
     */
    private function normalizeEntitiesConfiguration(array $entitiesConfiguration)
    {
        $normalizedConfiguration = array();

        foreach ($entitiesConfiguration as $entityLabel => $entityConfiguration) {
            // config formats #1 and #2
            if (!is_array($entityConfiguration)) {
                $entityConfiguration = array('class' => $entityConfiguration);
            }

            $entityClassParts = explode('\\', $entityConfiguration['class']);
            $entityName = end($entityClassParts);

            // config format #1 doesn't define custom labels: use the entity name as label
            $entityConfiguration['label'] = is_integer($entityLabel) ? $entityName : $entityLabel;

            $normalizedConfiguration[$entityName] = $entityConfiguration;
        }

        return $normalizedConfiguration;
    }

    /**
     * Normalizes and initializes the configuration of the given entities to
     * simplify the option processing of the other methods and functions.
     *
     * @param  array $entitiesConfiguration
     * @return array The configured entities
     */
    private function processEntitiesConfiguration(array $entitiesConfiguration)
    {
        $entities = array();

        foreach ($entitiesConfiguration as $entityName => $entityConfiguration) {
            // copy the original entity configuration to not lose any of its options
            $config = $entityConfiguration;

            // if the common 'form' config is defined, and 'new' or 'edit' config are
            // undefined, just copy the 'form' config into them to simplify the rest of the code
            if (isset($config['form']['fields']) && !isset($config['edit']['fields'])) {
                $config['edit']['fields'] = $config['form']['fields'];
            }
            if (isset($config['form']['fields']) && !isset($config['new']['fields'])) {
                $config['new']['fields'] = $config['form']['fields'];
            }

            // configuration for the actions related to the entity ('list', 'edit', etc.)
            foreach (array('edit', 'list', 'new', 'show') as $action) {
                // if needed, initialize options to simplify further configuration processing
                if (!isset($config[$action])) {
                    $config[$action] = array('fields' => array());
                }

                if (!isset($config[$action]['fields'])) {
                    $config[$action]['fields'] = array();
                }

                if (count($config[$action]['fields']) > 0) {
                    $config[$action]['fields'] = $this->normalizeFieldsConfiguration($config[$action]['fields'], $action, $entityConfiguration['class']);
                }
            }

            $entities[$entityName] = $config;
        }

        return $entities;
    }

    /**
     * The name of the entity is used in the URLs of the application to define the
     * entity which should be used for each action. Obviously, the entity name
     * must be unique in the application to identify entities unequivocally.
     *
     * This method ensures that all entity names are unique by appending some suffix
     * to repeated names until they are unique.
     *
     * @param  array $entitiesConfiguration
     * @return array The entities configuration with unique entity names
     */
    private function ensureThatEntityNamesAreUnique($entitiesConfiguration)
    {
        $configuration = array();
        $existingEntityNames = array();

        foreach ($entitiesConfiguration as $entityName => $entityConfiguration) {
            while (in_array($entityName, $existingEntityNames)) {
                $entityName .= '_';
            }
            $existingEntityNames[] = $entityName;

            $configuration[$entityName] = $entityConfiguration;
            $configuration[$entityName]['name'] = $entityName;
        }

        return $configuration;
    }

    /**
     * Actions can define their fields using two different formats:
     *
     * # Config format #1: simple configuration
     * easy_admin:
     *     Client:
     *         # ...
     *         list:
     *             fields: ['id', 'name', 'email']
     *
     * # Config format #2: extended configuration
     * easy_admin:
     *     Client:
     *         # ...
     *         list:
     *             fields: ['id', 'name', { property: 'email', label: 'Contact' }]
     *
     * This method processes both formats to produce a common form field configuration
     * format used in the rest of the application.
     *
     * @param  array  $fieldsConfiguration
     * @param  string $action              The current action (this argument is needed to create good error messages)
     * @param  string $entityClass         The class of the current entity (this argument is needed to create good error messages)
     * @return array  The configured entity fields
     */
    private function normalizeFieldsConfiguration(array $fieldsConfiguration, $action, $entityClass)
    {
        $fields = array();

        foreach ($fieldsConfiguration as $field) {
            if (is_string($field)) {
                // Config format #1: field is just a string representing the entity property
                $fieldConfiguration = array('property' => $field);
            } elseif (is_array($field)) {
                // Config format #1: field is an array that defines one or more
                // options. check that the mandatory 'property' option is set
                if (!array_key_exists('property', $field)) {
                    throw new \RuntimeException(sprintf('One of the values of the "fields" option for the "%s" action of the "%s" entity does not define the "property" option.', $action, $entityClass));
                }

                $fieldConfiguration = $field;
            } else {
                throw new \RuntimeException(sprintf('The values of the "fields" option for the "$s" action of the "%s" entity can only be strings or arrays.', $action, $entityClass));
            }

            $fieldName = $fieldConfiguration['property'];
            $fields[$fieldName] = $fieldConfiguration;
        }

        return $fields;
    }
}
