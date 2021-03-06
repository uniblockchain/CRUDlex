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

use CRUDlex\TwigExtensions;
use Pimple\Container;

/**
* Provides and setups the Twig extensions like filters for Silex.
*/
class TwigSetup
{

    /**
     * Registers all extensions.
     *
     * @param Container $app
     * the current application
     */
    public function registerTwigExtensions(Container $app)
    {
        $twigExtensions = new TwigExtensions();
        $app->extend('twig', function(\Twig_Environment $twig) use ($twigExtensions) {
            $twig->addFilter(new \Twig_SimpleFilter('arrayColumn', 'array_column'));
            $twig->addFilter(new \Twig_SimpleFilter('languageName', [$twigExtensions, 'getLanguageName']));
            $twig->addFilter(new \Twig_SimpleFilter('float', [$twigExtensions, 'formatFloat']));
            $twig->addFilter(new \Twig_SimpleFilter('basename', 'basename'));
            $twig->addFilter(new \Twig_SimpleFilter('formatDate', [$twigExtensions, 'formatDate']));
            $twig->addFilter(new \Twig_SimpleFilter('formatDateTime', [$twigExtensions, 'formatDateTime']));
            return $twig;
        });
    }

}
