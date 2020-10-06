<?php
/**
 * @license see LICENSE
 */

namespace SerpsCli\Command\Google;


use function file_get_contents;
use Serps\Core\Browser\Browser;
use Serps\Core\Http\Proxy;
use Serps\Core\Serp\ResultDataInterface;
use Serps\HttpClient\CurlClient;
use Serps\HttpClient\PhantomJsClient;
use Serps\SearchEngine\Google\GoogleClient;
use Serps\SearchEngine\Google\GoogleUrl;
use Serps\SearchEngine\Google\NaturalResultType;
use Serps\SearchEngine\Google\Page\GoogleSerp;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Search extends Command
{

    public function brief()
    {
        return 'Make a google search.';
    }

    public function configure()
    {
        $this
            ->setName('google:search')
            ->setDescription('Make a google search')
            ->addArgument('keywords', InputArgument::OPTIONAL, 'keywords to search for');


        $this->addOption(
            'tld',
            null,
            InputOption::VALUE_OPTIONAL,
            'google tld to search, e.g "--tld=co.uk" to search google.co.uk',
            'com'
        );

        $this->addOption(
            'lr',
            null,
            InputOption::VALUE_OPTIONAL,
            'language restriction'
        );

        $this->addOption(
            'http-client',
            null,
            InputOption::VALUE_OPTIONAL,
            'http client to use (default curl)',
            'curl'
        );

        $this->addOption(
            'proxy',
            null,
            InputOption::VALUE_OPTIONAL,
            'use the given proxy, e.g "--proxy=http://my-proxy-host:8080"'
        );

        $this->addOption(
            'page',
            null,
            InputOption::VALUE_OPTIONAL,
            'The google page number',
            1
        );

        $this->addOption(
            'dump',
            null,
            InputOption::VALUE_OPTIONAL,
            'dump the dom into the given file. Useful for debugging purpose.'
        );

        $this->addOption(
            'force-dump',
            null,
            null,
            'Force the dump option to override if the file exists.'
        );

        $this->addOption(
            'mobile',
            null,
            null,
            'Use a mobile user agent string to get mobile results.'
        );

        $this->addOption(
            'user-agent',
            null,
            InputOption::VALUE_OPTIONAL,
            'custom user agent to set for the search'
        );

        $this->addOption(
            'res-per-page',
            null,
            InputOption::VALUE_OPTIONAL,
            'The number of results per page (max 100).',
            10
        );

        $this->addOption(
            'file',
            null,
            InputOption::VALUE_OPTIONAL,
            'Parse a local file instead of doing a http call.'
        );
    }



    public function execute(InputInterface $input, OutputInterface $output){

        // get keyword
        $keywords = $input->getArgument('keywords');

        // get file
        $file = $input->getOption('file');

        // file or keyword is required
        if (!$keywords && !$file) {
            throw new RuntimeException('You have to specify keywords to search for a a file (--file) to parse.');
        }


        // PARSE OPTIONS
        $tld = $input->getOption('tld');
        $lr = $input->getOption('lr');

        $page = $input->getOption('page');
        $resPerPage = $input->getOption('res-per-page');

        // Allow to dump html response in a file
        $dump = $input->getOption('dump');
        if($dump){

            $forceDump = $input->getOption('force-dump');

            // TODO: checked again before saving (race condition)
            if(!$forceDump && file_exists($dump)){
                throw new \Exception('file ' . $dump . ' already exists. Use --force-dump to allow file override.');
            } elseif (!is_writable(dirname($dump))) {
                throw new \Exception('file ' . $dump . ' cannot be written');
            }
        }

        $url = new GoogleUrl("www.google.$tld");
        $url->setSearchTerm($keywords);
        $url->setPage($page);
        $url->setResultsPerPage($resPerPage);
        if($lr){
            $url->setLanguageRestriction($lr);
        }




        if($file){
            // OPEN A FILE

            $response = new GoogleSerp(file_get_contents($file), $url);
        } else {
            // QUERY

            // USER AGENT

            $userAgent = $input->getOption('user-agent');

            $isMobile = $input->getOption('mobile');


            if (!$userAgent) {
                if ($isMobile) {
                    $userAgent = 'Mozilla/5.0 (Linux; Android 5.0; SM-G900P Build/LRX21T) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/65.0.3325.181 Mobile Safari/537.36';
                } else {
                    $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu Chromium/65.0.3325.181 Chrome/65.0.3325.181 Safari/537.36';
                }
            } else {
                if ($isMobile && $output->isVerbose()) {
                    $output->writeln(
                        '<info>You used both of --mobile and --user-agent option, '
                        . 'the given user agent string will be used instead of the default mobile user agent</info>'
                    );
                }
            }




            // PROXY

            $proxyString = $input->getOption('proxy');
            $proxy = null;
            if($proxyString){
                $proxy = Proxy::createFromString($proxyString);
            }


            // PREPARE CLIENT

            $client = $input->getOption('http-client');
            switch($client){
                case "phantomjs":
                    $httpClient = new PhantomJsClient();
                    break;
                default:
                    $client = "curl";
                    $httpClient = new CurlClient();
            }

            $browser = new Browser($httpClient, $userAgent, null, null, $proxy);
            $googleClient = new GoogleClient($browser);

            $response = $googleClient->query($url);
        }


        // DUMP IF ASKED

        if($dump){
            $done = file_put_contents($dump, $response->getDom()->saveHTML());
            if(!$done){
                throw new \Exception('An error happened while dumping the file ' . $dump);
            }
        }



        // OUTPUT RESULTS

        $evaluated = $response->javascriptIsEvaluated();

        $naturalResults = $response->getNaturalResults();
        $items = $naturalResults->getItems();


        $data = [
            'initial-url' => (string) $url,
            'url' => (string) $response->getUrl(),
            'http-client' => isset($client) ? $client : null,
            'evaluated' => $evaluated,
            'isMobileDom' => $response->isMobile(),
            'natural-results-count' => 0,
            'total-count' => $response->getNumberOfResults(),
            'natural-results' => [],
            'related-searches' => []
        ];

        // Feed natural-results
        foreach($items as $item){

            $r = [
                'types' => $item->getTypes()
            ];

            $this->parseSingleResult($item, $r);

            $data['natural-results'][] = $r;
        }

        // Feed related-searches
        foreach($response->getRelatedSearches() as $search){
            $data['related-searches'][] = [
                'title' => $search->title,
                'url' => $search->url
            ];
        }

        $data['natural-results-count'] = count($data['natural-results']);

        $output->write(json_encode($data, JSON_PRETTY_PRINT));

    }

    private function parseSingleResult(ResultDataInterface $item, &$output){


        // CLASSICAL
        if ($item->is(NaturalResultType::CLASSICAL)) {
            $output['title'] = $item->title;
            $output['url'] = (string) $item->url;


        // TOP STORIES
        } elseif ($item->is(NaturalResultType::TOP_STORIES)) {
            $output['isCarousel'] = $item->isCarousel;
            $news = [];
            foreach ($item->news as $newsItem) {
                $news[] = [
                    'title' => $newsItem->title,
                    'url' => $newsItem->url
                ];

            }
            $output['news'] = $news;


        // VIDEO GROUP
        } elseif ($item->is(NaturalResultType::VIDEO_GROUP)) {

            $output['videos'] = [];

            foreach ($item->videos as $video) {
                $output['videos'][] = ['title' => $video->title];
            }


        // KNOWLEDGE
        } elseif ($item->is(NaturalResultType::KNOWLEDGE)) {

            $output['title'] = $item->title;


        // IMAGE GROUP
        } elseif ($item->is(NaturalResultType::IMAGE_GROUP)) {
            $output['isCarousel'] = $item->isCarousel;
            $output['imagesCount'] = count($item->images);
        }
    }

}
