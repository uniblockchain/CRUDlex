<?php

/*
 * This file is part of the CRUDlex package.
 *
 * (c) Philip Lehmann-Böhm <philip@philiplb.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CRUDlex\Silex;

use CRUDlex\EntityDefinition;
use CRUDlex\EntityDefinitionFactory;
use CRUDlex\EntityDefinitionFactoryInterface;
use CRUDlex\EntityDefinitionValidator;
use CRUDlex\YamlReader;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\BootableProviderInterface;
use Silex\Application;
use Silex\Provider\LocaleServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\TranslationServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The ServiceProvider setups and initializes the whole CRUD system.
 * After adding it to your Silex-setup, it offers access to AbstractData
 * instances, one for each defined entity off the CRUD YAML file.
 */
class ServiceProvider implements ServiceProviderInterface, BootableProviderInterface
{

    /**
     * Holds the data instances.
     * @var array
     */
    protected $datas;

    /**
     * Holds the map for overriding templates.
     * @var array
     */
    protected $templates = [];

    /**
     * Holds whether CRUDlex manages i18n.
     * @var bool
     */
    protected $manageI18n = true;

    /**
     * Holds the URL generator.
     * @var \Symfony\Component\Routing\Generator\UrlGenerator
     */
    protected $urlGenerator;

    /**
     * Initializes needed but yet missing service providers.
     *
     * @param Container $app
     * the application container
     */
    protected function initMissingServiceProviders(Container $app)
    {

        if (!$app->offsetExists('translator')) {
            $app->register(new LocaleServiceProvider());
            $app->register(new TranslationServiceProvider(), [
                'locale_fallbacks' => ['en'],
            ]);
        }

        if (!$app->offsetExists('session')) {
            $app->register(new SessionServiceProvider());
        }

        if (!$app->offsetExists('twig')) {
            $app->register(new TwigServiceProvider());
        }
        $app['twig.loader.filesystem']->addPath(__DIR__.'/../../views/', 'crud');
    }

    /**
     * Initializes the available locales.
     *
     * @param Translator $translator
     * the translator
     *
     * @return array
     * the available locales
     */
    protected function initLocales(Translator $translator)
    {
        $locales   = $this->getLocales();
        $localeDir = __DIR__.'/../../locales';
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach ($locales as $locale) {
            $translator->addResource('yaml', $localeDir.'/'.$locale.'.yml', $locale);
        }
        return $locales;
    }

    /**
     * Initializes the children of the data entries.
     */
    protected function initChildren()
    {
        foreach ($this->datas as $name => $data) {
            $fields = $data->getDefinition()->getFieldNames();
            foreach ($fields as $field) {
                if ($data->getDefinition()->getType($field) == 'reference') {
                    $this->datas[$data->getDefinition()->getSubTypeField($field, 'reference', 'entity')]->getDefinition()->addChild($data->getDefinition()->getTable(), $field, $name);
                }
            }
        }
    }

    /**
     * Gets a map with localized entity labels from the CRUD YML.
     *
     * @param array $locales
     * the available locales
     * @param array $crud
     * the CRUD entity map
     *
     * @return array
     * the map with localized entity labels
     */
    protected function getLocaleLabels(array $locales, array $crud)
    {
        $localeLabels = [];
        foreach ($locales as $locale) {
            if (array_key_exists('label_'.$locale, $crud)) {
                $localeLabels[$locale] = $crud['label_'.$locale];
            }
        }
        return $localeLabels;
    }

    /**
     * Configures the EntityDefinition according to the given
     * CRUD entity map.
     *
     * @param EntityDefinition $definition
     * the definition to configure
     * @param array $crud
     * the CRUD entity map
     */
    protected function configureDefinition(EntityDefinition $definition, array $crud)
    {
        $toConfigure = [
            'deleteCascade',
            'listFields',
            'filter',
            'childrenLabelFields',
            'pageSize',
            'initialSortField',
            'initialSortAscending',
            'navBarGroup',
            'optimisticLocking',
            'hardDeletion',
        ];
        foreach ($toConfigure as $field) {
            if (array_key_exists($field, $crud)) {
                $function = 'set'.ucfirst($field);
                $definition->$function($crud[$field]);
            }
        }
    }

    /**
     * Creates and setups an EntityDefinition instance.
     *
     * @param Translator $translator
     * the Translator to use for some standard field labels
     * @param EntityDefinitionFactoryInterface $entityDefinitionFactory
     * the EntityDefinitionFactory to use
     * @param array $locales
     * the available locales
     * @param array $crud
     * the parsed YAML of a CRUD entity
     * @param string $name
     * the name of the entity
     *
     * @return EntityDefinition
     * the EntityDefinition good to go
     */
    protected function createDefinition(Translator $translator, EntityDefinitionFactoryInterface $entityDefinitionFactory, array $locales, array $crud, $name)
    {
        $label               = array_key_exists('label', $crud) ? $crud['label'] : $name;
        $localeLabels        = $this->getLocaleLabels($locales, $crud);
        $standardFieldLabels = [
            'id' => $translator->trans('crudlex.label.id'),
            'created_at' => $translator->trans('crudlex.label.created_at'),
            'updated_at' => $translator->trans('crudlex.label.updated_at')
        ];

        $definition = $entityDefinitionFactory->createEntityDefinition(
            $crud['table'],
            $crud['fields'],
            $label,
            $localeLabels,
            $standardFieldLabels,
            $this
        );
        $this->configureDefinition($definition, $crud);
        return $definition;
    }

    /**
     * Validates the parsed entity definition.
     *
     * @param Container $app
     * the application container
     * @param array $entityDefinition
     * the entity definition to validate
     */
    protected function validateEntityDefinition(Container $app, array $entityDefinition)
    {
        $doValidate = !$app->offsetExists('crud.validateentitydefinition') || $app['crud.validateentitydefinition'] === true;
        if ($doValidate) {
            $validator = $app->offsetExists('crud.entitydefinitionvalidator')
                ? $app['crud.entitydefinitionvalidator']
                : new EntityDefinitionValidator();
            $validator->validate($entityDefinition);
        }
    }

    /**
     * Initializes the instance.
     *
     * @param string|null $crudFileCachingDirectory
     * the writable directory to store the CRUD YAML file cache
     * @param Container $app
     * the application container
     */
    public function init($crudFileCachingDirectory, Container $app)
    {

        $this->urlGenerator = $app['url_generator'];

        $reader     = new YamlReader($crudFileCachingDirectory);
        $parsedYaml = $reader->read($app['crud.file']);

        $this->validateEntityDefinition($app, $parsedYaml);

        $locales                 = $this->initLocales($app['translator']);
        $this->datas             = [];
        $entityDefinitionFactory = $app->offsetExists('crud.entitydefinitionfactory') ? $app['crud.entitydefinitionfactory'] : new EntityDefinitionFactory();
        foreach ($parsedYaml as $name => $crud) {
            $definition         = $this->createDefinition($app['translator'], $entityDefinitionFactory, $locales, $crud, $name);
            $this->datas[$name] = $app['crud.datafactory']->createData($definition, $app['crud.filesystem']);
        }

        $this->initChildren();

    }

    /**
     * Implements ServiceProviderInterface::register() registering $app['crud'].
     * $app['crud'] contains an instance of the ServiceProvider afterwards.
     *
     * @param Container $app
     * the Container instance of the Silex application
     */
    public function register(Container $app)
    {
        if (!$app->offsetExists('crud.filesystem')) {
            $app['crud.filesystem'] = new Filesystem(new Local(getcwd()));
        }
        $app['crud'] = function() use ($app) {
            $result = new static();
            $result->setTemplate('layout', '@crud/layout.twig');
            $crudFileCachingDirectory = $app->offsetExists('crud.filecachingdirectory') ? $app['crud.filecachingdirectory'] : null;
            $result->init($crudFileCachingDirectory, $app);
            return $result;
        };
    }

    /**
     * Initializes the crud service right after boot.
     *
     * @param Application $app
     * the Container instance of the Silex application
     */
    public function boot(Application $app)
    {
        $this->initMissingServiceProviders($app);
        $twigSetup = new TwigSetup();
        $twigSetup->registerTwigExtensions($app);
    }

    /**
     * Getter for the AbstractData instances.
     *
     * @param string $name
     * the entity name of the desired Data instance
     *
     * @return AbstractData
     * the AbstractData instance or null on invalid name
     */
    public function getData($name)
    {
        if (!array_key_exists($name, $this->datas)) {
            return null;
        }
        return $this->datas[$name];
    }

    /**
     * Getter for all available entity names.
     *
     * @return string[]
     * a list of all available entity names
     */
    public function getEntities()
    {
        return array_keys($this->datas);
    }

    /**
     * Getter for the entities for the navigation bar.
     *
     * @return string[]
     * a list of all available entity names with their group
     */
    public function getEntitiesNavBar()
    {
        $result = [];
        foreach ($this->datas as $entity => $data) {
            $navBarGroup = $data->getDefinition()->getNavBarGroup();
            if ($navBarGroup !== 'main') {
                $result[$navBarGroup][] = $entity;
            } else {
                $result[$entity] = 'main';
            }
        }
        return $result;
    }

    /**
     * Sets a template to use instead of the build in ones.
     *
     * @param string $key
     * the template key to use in this format:
     * $section.$action.$entity
     * $section.$action
     * $section
     * @param string $template
     * the template to use for this key
     */
    public function setTemplate($key, $template)
    {
        $this->templates[$key] = $template;
    }

    /**
     * Determines the Twig template to use for the given parameters depending on
     * the existance of certain template keys set in this order:
     *
     * $section.$action.$entity
     * $section.$action
     * $section
     *
     * If nothing exists, this string is returned: "@crud/<action>.twig"
     *
     * @param string $section
     * the section of the template, either "layout" or "template"
     * @param string $action
     * the current calling action like "create" or "show"
     * @param string $entity
     * the current calling entity
     *
     * @return string
     * the best fitting template
     */
    public function getTemplate($section, $action, $entity)
    {
        $sectionAction = $section.'.'.$action;

        $offsets = [
            $sectionAction.'.'.$entity,
            $section.'.'.$entity,
            $sectionAction,
            $section
        ];
        foreach ($offsets as $offset) {
            if (array_key_exists($offset, $this->templates)) {
                return $this->templates[$offset];
            }
        }

        return '@crud/'.$action.'.twig';
    }

    /**
     * Sets the locale to be used.
     *
     * @param string $locale
     * the locale to be used.
     */
    public function setLocale($locale)
    {
        foreach ($this->datas as $data) {
            $data->getDefinition()->setLocale($locale);
        }
    }

    /**
     * Gets the available locales.
     *
     * @return array
     * the available locales
     */
    public function getLocales()
    {
        $localeDir     = __DIR__.'/../../locales';
        $languageFiles = scandir($localeDir);
        $locales       = [];
        foreach ($languageFiles as $languageFile) {
            if (in_array($languageFile, ['.', '..'])) {
                continue;
            }
            $extensionPos = strpos($languageFile, '.yml');
            if ($extensionPos !== false) {
                $locale    = substr($languageFile, 0, $extensionPos);
                $locales[] = $locale;
            }
        }
        sort($locales);
        return $locales;
    }

    /**
     * Gets whether CRUDlex manages the i18n.
     * @return bool
     * true if so
     */
    public function isManageI18n()
    {
        return $this->manageI18n;
    }

    /**
     * Sets whether CRUDlex manages the i18n.
     * @param bool $manageI18n
     * true if so
     */
    public function setManageI18n($manageI18n)
    {
        $this->manageI18n = $manageI18n;
    }

    /**
     * Generates an URL.
     * @param string $name
     * the name of the route
     * @param mixed $parameters
     * an array of parameters
     * @return null|string
     * the generated URL
     */
    public function generateURL($name, $parameters)
    {
        return $this->urlGenerator->generate($name, $parameters);
    }

}
