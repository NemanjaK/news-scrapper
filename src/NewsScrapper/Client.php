<?php

namespace Zrashwani\NewsScrapper;

use Goutte\Client as GoutteClient;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\BrowserKit\CookieJar;

/**
 * Client to scrap article/news contents from serveral news sources
 *
 * @author Zeid Rashwani <zaid@zrashwani.com>
 */
class Client
{

    protected $scrapClient;
    protected $adaptersList = ['Microdata', 'HAtom', 'OpenGraph', 'JsonLD', 'Parsely', 'Default'];

    /**
     * Adapter to scrap content
     * @var Adapters\AbstractAdapter
     */
    protected $adapter;

    /**
     * Constructor
     */
    public function __construct($adapter_name = null, CookieJar $cookie_jar = null)
    {
        $this->scrapClient = new GoutteClient([], null, $cookie_jar);
        
        $this->scrapClient->followRedirects();
        $this->scrapClient->getClient()->setDefaultOption(
            'config/curl/'.
            CURLOPT_SSL_VERIFYHOST,
            false
        );
        $this->scrapClient->getClient()->setDefaultOption(
            'config/curl/'.
            CURLOPT_SSL_VERIFYPEER,
            false
        );
        
        $this->setAdapter($adapter_name);
    }

    /**
     * Getting selected adapter
     * @return Adapters\AbstractAdapter
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * Setting adapter preferred for scrapping
     * @param string $adapter_name
     * @throws \Exception
     */
    public function setAdapter($adapter_name)
    {
        $adapterClass = "\Zrashwani\NewsScrapper\Adapters\\".$adapter_name."Adapter";
        if (class_exists($adapterClass)) {
            $this->adapter = new $adapterClass();
        } else {
            $this->adapter = null;
        }

        return $this;
    }

    /**
     * scrap one source of news
     * @param string   $baseUrl      url to scrap list of news from
     * @param string   $linkSelector css selector for news links in page
     * @param int|NULL $limit        limit of news article to scrap,
     *      if not set it will scrap all matching the selector
     * @return array array of article items scrapped
     */
    public function scrapLinkGroup($baseUrl, $linkSelector, $limit = null)
    {
        $crawler = $this->scrapClient->request('GET', $baseUrl);

        $scrap_result = array();
        $theAdapter = new Adapters\DefaultAdapter();
        $theAdapter->currentUrl = $baseUrl;

        $isXpath = Selector::isXPath($linkSelector);
        $method = ($isXpath === false) ? 'filter' : 'filterXPath';
        
        $crawler->$method($linkSelector)
            ->each(
                function(Crawler $link_node) use (&$scrap_result, $theAdapter, &$limit) {
                    if (!is_null($limit) && count($scrap_result) >= $limit) {
                        return;
                    }
                            $link = $theAdapter
                                ->normalizeLink($link_node->attr('href'), true); //remove hash before scrapping

                            $article_info = $this->getLinkData($link);
                            $this->setAdapter(''); //reset default adapter after scrapping one link
                            $scrap_result[$link] = $article_info;
                }
            );

        return $scrap_result;
    }

    /**
     * Scrap information for single url
     * @param string $link
     * @return \stdClass
     */
    public function getLinkData($link)
    {
        $article_info = new \stdClass();
        $article_info->url = $link;

        $pageCrawler = $this->scrapClient->request('GET', $article_info->url);

        $selected_adapter = $this->getAdapter();
        if ($selected_adapter !== null) {
            $this->extractPageData($article_info, $pageCrawler, $selected_adapter);
        } else { //apply smart scrapping by iterating over all adapters
            foreach ($this->adaptersList as $adapter_name) {
                $this->setAdapter($adapter_name);
                $this->extractPageData($article_info, $pageCrawler, $this->getAdapter());
            }
        }


        return $article_info;
    }

    /**
     * Extracting page data from domCrawler according to rules defined by adapter
     * @param \stdClass                                        $article_info
     * @param Crawler                                          $pageCrawler
     * @param \Zrashwani\NewsScrapper\Adapters\AbstractAdapter $adapter      adapter used for scrapping
     */
    protected function extractPageData(
        $article_info,
        Crawler $pageCrawler,
        Adapters\AbstractAdapter $adapter
    ) {
        $adapter->currentUrl = $article_info->url; //associate link url to adapter
        
        $article_info->title = empty($article_info->title) === true ?
                    $adapter->extractTitle($pageCrawler) : $article_info->title;
        $article_info->image = empty($article_info->image) === true ?
                $adapter->extractImage($pageCrawler, $article_info->url) : $article_info->image;
        $article_info->description = empty($article_info->description) === true ?
                $adapter->extractDescription($pageCrawler) : $article_info->description;
        $article_info->keywords = !isset($article_info->keywords) || count($article_info->keywords) === 0 ?
                $adapter->extractKeywords($pageCrawler) : $article_info->keywords;
        
        $article_info->author = empty($article_info->author) === true ?
                $adapter->extractAuthor($pageCrawler) : $article_info->author;
        $article_info->publishDate = empty($article_info->publishDate) === true ?
                $adapter->extractPublishDate($pageCrawler) : $article_info->publishDate;
        $article_info->body = empty($article_info->body) === true ?
                $adapter->extractBody($pageCrawler) : $article_info->body;
        
    }
}
