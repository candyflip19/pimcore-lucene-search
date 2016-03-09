<?php

namespace LuceneSearch\Tool;

use LuceneSearch\Model\Configuration;
use LuceneSearch\Model\Parser;
use LuceneSearch\Model\SitemapBuilder;

class Executer {

    public static function runCrawler()
    {
        $running = Configuration::get('frontend.crawler.running');

        if( $running === TRUE)
        {
            return FALSE;
        }

        $indexDir = \LuceneSearch\Plugin::getFrontendSearchIndex();

        if ($indexDir)
        {
            exec('rm -Rf ' . str_replace('/index/', '/tmpindex', $indexDir));

            \Logger::debug('LuceneSearch: rm -Rf ' . str_replace('/index/', '/tmpindex', $indexDir));
            \Logger::debug('LuceneSearch: Starting crawl');

            try
            {
                $urls = Configuration::get('frontend.urls');
                $invalidLinkRegexesSystem = Configuration::get('frontend.invalidLinkRegexes');
                $invalidLinkRegexesEditable = Configuration::get('frontend.invalidLinkRegexesEditable');

                if (!empty($invalidLinkRegexesEditable) and !empty($invalidLinkRegexesSystem))
                {
                    $invalidLinkRegexes = array_merge($invalidLinkRegexesEditable, array($invalidLinkRegexesSystem));
                }
                else if (!empty($invalidLinkRegexesEditable))
                {
                    $invalidLinkRegexes = $invalidLinkRegexesEditable;
                }
                else if (!empty($invalidLinkRegexesSystem))
                {
                    $invalidLinkRegexes = array($invalidLinkRegexesSystem);
                }
                else
                {
                    $invalidLinkRegexes = array();
                }

                self::setCrawlerState('frontend', 'started', true, true);

                try
                {
                    $parser = new Parser();

                    $parser
                        ->setDepth( Configuration::get('frontend.crawler.maxLinkDepth') )
                        ->setValidLinkRegexes( Configuration::get('frontend.validLinkRegexes') )
                        ->setInvalidLinkRegexes( $invalidLinkRegexes )
                        ->setSearchStartIndicator(Configuration::get('frontend.crawler.contentStartIndicator'))
                        ->setSearchEndIndicator(Configuration::get('frontend.crawler.contentEndIndicator'))
                        ->setAllowSubdomain( FALSE )
                        ->setAllowedSchemes( Configuration::get('frontend.allowedSchemes') )
                        ->setDownloadLimit( Configuration::get('frontend.crawler.maxDownloadLimit') )
                        ->setSeed( $urls[0] );

                    $parser->startParser($urls);

                    $parser->optimizeIndex();

                }

                catch(\Exception $e) { }

                self::setCrawlerState('frontend', 'finished', false, true);

                //only remove index, if tmp exists!
                $tmpIndex = str_replace('/index', '/tmpindex', $indexDir);

                if( is_dir( $tmpIndex ) )
                {
                    exec('rm -Rf ' . $indexDir);
                    \Logger::debug('LuceneSearch: rm -Rf ' . $indexDir);

                    exec('cp -R ' . substr($tmpIndex, 0, -1) . ' ' . substr($indexDir, 0, -1));

                    \Logger::debug('LuceneSearch: cp -R ' . substr($tmpIndex, 0, -1) . ' ' . substr($indexDir, 0, -1));
                    \Logger::debug('LuceneSearch: replaced old index');
                    \Logger::info('LuceneSearch: Finished crawl');
                }
                else
                {
                    \Logger::err('LuceneSearch: skipped index replacing. no tmp index found.');
                }

            }
            catch (\Exception $e)
            {
                \Logger::err($e);
                throw $e;
            }

        }

    }

    /**
     * @static
     * @param bool $playNice
     * @param bool $isFrontendCall
     * @return bool
     */
    public static function stopCrawler($playNice = true, $isFrontendCall = false)
    {
        \Logger::debug('LuceneSearch: forcing frontend crawler stop');

        self::setStopLock('frontend', true);

        //just to make sure nothing else starts the crawler right now
        self::setCrawlerState('frontend', 'started', false);

        \Logger::debug('LuceneSearch: forcing frontend crawler stop.');

        self::setStopLock('frontend', false);
        self::setCrawlerState('frontend', 'finished', false);

        \Zend_Registry::set('dings', true);

        return true;

    }

    /**
     * @param string $crawler frontend | backend
     * @param string $action started | finished
     * @param bool $running
     * @param bool $setTime
     * @return void
     */
    public static function setCrawlerState($crawler, $action, $running, $setTime = true)
    {
        $run = FALSE;

        if ($running)
        {
            $run = TRUE;
        }

        Configuration::set($crawler .'.crawler.forceStart', FALSE);
        Configuration::set($crawler .'.crawler.running', $run);

        if( $action == 'started' && $running == TRUE )
        {
            touch( PIMCORE_TEMPORARY_DIRECTORY . '/lucene-crawler.tmp');
        }

        if( $action == 'finished' && $run == FALSE)
        {
            if( is_file( PIMCORE_TEMPORARY_DIRECTORY . '/lucene-crawler.tmp' ) )
            {
                unlink( PIMCORE_TEMPORARY_DIRECTORY . '/lucene-crawler.tmp');
            }
        }

        if ($setTime)
        {
            Configuration::set($crawler .'.crawler.' . $action, time());
        }
    }

    public static function setStopLock($crawler, $flag = true)
    {
        $stop = TRUE;

        if (!$flag)
        {
            $stop = FALSE;
        }

        Configuration::set($crawler .'.crawler.forceStop', $stop);

        if ($stop)
        {
            Configuration::set($crawler .'.crawler.forceStopInitiated', time());
        }
    }

    public static function generateSitemap()
    {
        $builder = new SitemapBuilder();
        $builder->generateSitemap();

        return FALSE;
    }
}