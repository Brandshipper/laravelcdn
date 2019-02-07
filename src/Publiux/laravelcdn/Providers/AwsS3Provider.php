<?php

namespace Publiux\laravelcdn\Providers;

use Aws\S3\BatchDelete;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Publiux\laravelcdn\Contracts\CdnHelperInterface;
use Publiux\laravelcdn\Providers\Contracts\ProviderInterface;
use Publiux\laravelcdn\Validators\Contracts\ProviderValidatorInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Class AwsS3Provider
 * Amazon (AWS) S3.
 *
 *
 * @category Driver
 *
 * @property string  $provider_url
 * @property string  $threshold
 * @property string  $version
 * @property string  $region
 * @property string  $credential_key
 * @property string  $credential_secret
 * @property string  $buckets
 * @property string  $acl
 * @property string  $cloudfront
 * @property string  $cloudfront_url
 * @property string  $use_path_style_endpoint
 * @property string $http
 * @property array  $compression
 * @property array  $mimetypes
 *
 * @author   Mahmoud Zalt <mahmoud@vinelab.com>
 */
class AwsS3Provider extends Provider implements ProviderInterface
{
    /**
     * All the configurations needed by this class with the
     * optional configurations default values.
     *
     * @var array
     */
    protected $default = [
        'url' => null,
        'threshold' => 10,
        'compression' => [
            'extensions' => [],
            'algorithm' => null,
            'level' => 9
        ],
        'mimetypes' => [
        ],
        'providers' => [
            'aws' => [
                's3' => [
                    'version' => null,
                    'region' => null,
                    'endpoint' => null,
                    'credentials' => null,
                    'buckets' => null,
                    'upload_folder' => '',
                    'http' => null,
                    'acl' => 'public-read',
                    'cloudfront' => [
                        'use' => false,
                        'cdn_url' => null,
                    ],
                    'use_path_style_endpoint' => false
                ],
            ],
        ],
    ];

    /**
     * Required configurations (must exist in the config file).
     *
     * @var array
     */
    protected $rules = ['version', 'region', 'key', 'secret', 'buckets', 'url', 'mimetypes'];

    /**
     * this array holds the parsed configuration to be used across the class.
     *
     * @var Array
     */
    protected $supplier;

    /**
     * @var Instance of Aws\S3\S3Client
     */
    protected $s3_client;

    /**
     * @var Instance of Guzzle\Batch\BatchBuilder
     */
    protected $batch;

    /**
     * @var \Publiux\laravelcdn\Contracts\CdnHelperInterface
     */
    protected $cdn_helper;

    /**
     * @var \Publiux\laravelcdn\Validators\Contracts\ConfigurationsInterface
     */
    protected $configurations;

    /**
     * @var \Publiux\laravelcdn\Validators\Contracts\ProviderValidatorInterface
     */
    protected $provider_validator;

    /**
     * @param \Symfony\Component\Console\Output\ConsoleOutput $console
     * @param \Publiux\laravelcdn\Validators\Contracts\ProviderValidatorInterface $provider_validator
     * @param \Publiux\laravelcdn\Contracts\CdnHelperInterface                    $cdn_helper
     */
    public function __construct(
        ConsoleOutput $console,
        ProviderValidatorInterface $provider_validator,
        CdnHelperInterface $cdn_helper
    ) {
        $this->console = $console;
        $this->provider_validator = $provider_validator;
        $this->cdn_helper = $cdn_helper;
    }

    /**
     * Read the configuration and prepare an array with the relevant configurations
     * for the (AWS S3) provider. and return itself.
     *
     * @param $configurations
     *
     * @return $this
     */
    public function init($configurations)
    {
        // merge the received config array with the default configurations array to
        // fill missed keys with null or default values.
        $this->default = array_replace_recursive($this->default, $configurations);

        $supplier = [
            'provider_url' => $this->default['url'],
            'threshold' => $this->default['threshold'],
            'version' => $this->default['providers']['aws']['s3']['version'],
            'region' => $this->default['providers']['aws']['s3']['region'],
            'credentials' => $this->default['providers']['aws']['s3']['credentials'],
            'endpoint' => $this->default['providers']['aws']['s3']['endpoint'],
            'buckets' => $this->default['providers']['aws']['s3']['buckets'],
            'acl' => $this->default['providers']['aws']['s3']['acl'],
            'cloudfront' => $this->default['providers']['aws']['s3']['cloudfront']['use'],
            'cloudfront_url' => $this->default['providers']['aws']['s3']['cloudfront']['cdn_url'],
            'http' => $this->default['providers']['aws']['s3']['http'],
            'upload_folder' => $this->default['providers']['aws']['s3']['upload_folder'],
            'use_path_style_endpoint' => $this->default['providers']['aws']['s3']['use_path_style_endpoint'],
            'compression' => $this->default['compression'],
            'mimetypes' => $this->default['mimetypes'],
        ];

        // check if any required configuration is missed
        $this->provider_validator->validate($supplier, $this->rules);

        $this->supplier = $supplier;

        return $this;
    }

    /**
     * Upload assets.
     *
     * @param $assets
     *
     * @return bool
     */
    public function upload($assets)
    {
        // connect before uploading
        $connected = $this->connect();

        if (!$connected) {
            return false;
        }

        // user terminal message
        $this->console->writeln('<fg=yellow>Comparing local files and bucket...</fg=yellow>');

        $assets = $this->getFilesToUpload($assets);

        // upload each asset file to the CDN
        $count = count($assets);
        if ($count > 0) {
            $this->console->writeln('<fg=yellow>Upload in progress......</fg=yellow>');
            foreach ($assets as $i => $file) {
                try {
                    $needsCompression = $this->needCompress($file);
                    $this->console->writeln(
                        '<fg=magenta>' . str_pad( number_format (100 / $count * ($i + 1), 2), 6, ' ',STR_PAD_LEFT) . '% </fg=magenta>' .
                        '<fg=cyan>Uploading file path: ' . $file->getRealpath() . '</fg=cyan>' .
                        ($needsCompression ? ' <fg=green>Compressed</fg=green>' : '')
                    );
                    $command = $this->s3_client->getCommand('putObject', [

                        // the bucket name
                        'Bucket' => $this->getBucket(),
                        // the path of the file on the server (CDN)
                        'Key' => $this->supplier['upload_folder'] . str_replace('\\', '/', $file->getPathName()),
                        // the path of the path locally
                        'Body' => $this->getFileContent($file, $needsCompression),
                        // the permission of the file

                        'ACL' => $this->acl,
                        'CacheControl' => $this->default['providers']['aws']['s3']['cache-control'],
                        'Metadata' => $this->default['providers']['aws']['s3']['metadata'],
                        'Expires' => $this->default['providers']['aws']['s3']['expires'],
                        'ContentType' => $this->getMimetype($file),
                        'ContentEncoding' => $needsCompression ? $this->compression['algorithm'] : 'identity',
                    ]);
//                var_dump(get_class($command));exit();


                    $this->s3_client->execute($command);
                } catch (S3Exception $e) {
                    $this->console->writeln('<fg=red>Upload error: '.$e->getMessage().'</fg=red>');
                    return false;
                }
            }

            // user terminal message
            $this->console->writeln('<fg=green>Upload completed successfully.</fg=green>');
        } else {
            // user terminal message
            $this->console->writeln('<fg=yellow>No new files to upload.</fg=yellow>');
        }

        return true;
    }

    /**
     * Create an S3 client instance
     * (Note: it will read the credentials form the .env file).
     *
     * @return bool
     */
    public function connect()
    {
        try {
            // Parsing credentials
            // Instantiate an S3 client
            $this->setS3Client(new S3Client([
                        'version' => $this->supplier['version'],
                        'region' => $this->supplier['region'],
                        'credentials' => $this->supplier['credentials'],
                        'endpoint' => $this->supplier['endpoint'],
                        'http' => $this->supplier['http'],
                        'use_path_style_endpoint' => $this->supplier['use_path_style_endpoint']
                    ]
                )
            );
        } catch (\Exception $e) {
            $this->console->writeln('<fg=red>Connection error: '.$e->getMessage().'</fg=red>');
            return false;
        }

        return true;
    }

    /**
     * @param $s3_client
     */
    public function setS3Client($s3_client)
    {
        $this->s3_client = $s3_client;
    }

    /**
     * Get files to upload
     *
     * @param $assets
     * @return mixed
     */
    private function getFilesToUpload($assets)
    {
        $filesOnAWS = new Collection([]);

        $files = $this->s3_client->listObjects([
            'Bucket' => $this->getBucket(),
        ]);

        if (!$files['Contents']) {
            //no files on bucket. lets upload everything found.
            return $assets;
        }

        foreach ($files['Contents'] as $file) {
            $a = [
                'Key' => $file['Key'],
                'Hash' => trim($file['ETag'], '"'),
                "LastModified" => $file['LastModified']->getTimestamp(),
                'Size' => $file['Size']
            ];
            $filesOnAWS->put($file['Key'], $a);
        }

        $assets = $assets->reject(function ($item) use ($filesOnAWS) {
            $key = str_replace('\\', '/', $item->getPathName());
            if (!$filesOnAWS->has($key)) {
                return false;
            }

            $fileOnAWS = $filesOnAWS->get($key);

            if (
                ($item->getMTime() === $fileOnAWS['LastModified']) &&
                ($item->getSize() === $fileOnAWS['Size'])
            ) {
                return false;
            }

            return !$this->calculateEtag($item->getPathName(), -8, $fileOnAWS['Hash']);
        });

        return $assets;
    }

    /**
     * @return array
     */
    public function getBucket()
    {
        // this step is very important, "always assign returned array from
        // magical function to a local variable if you need to modify it's
        // state or apply any php function on it." because the returned is
        // a copy of the original variable. this prevent this error:
        // Indirect modification of overloaded property
        // Vinelab\Cdn\Providers\AwsS3Provider::$buckets has no effect
        $bucket = $this->buckets;

        return rtrim(key($bucket), '/');
    }

    /**
     * Empty bucket.
     *
     * @return bool
     */
    public function emptyBucket()
    {

        // connect before uploading
        $connected = $this->connect();

        if (!$connected) {
            return false;
        }

        // user terminal message
        $this->console->writeln('<fg=yellow>Emptying in progress...</fg=yellow>');

        try {

            // Get the contents of the bucket for information purposes
            $contents = $this->s3_client->listObjects([
                'Bucket' => $this->getBucket(),
                'Key' => '',
            ]);

            // Check if the bucket is already empty
            if (!$contents['Contents']) {
                $this->console->writeln('<fg=green>The bucket '.$this->getBucket().' is already empty.</fg=green>');

                return true;
            }

            // Empty out the bucket
            $empty = BatchDelete::fromListObjects($this->s3_client, [
                'Bucket' => $this->getBucket(),
                'Prefix' => null,
            ]);

            $empty->delete();
        } catch (S3Exception $e) {
            $this->console->writeln('<fg=red>Deletion error: '.$e->getMessage().'</fg=red>');
            return false;
        }

        $this->console->writeln('<fg=green>The bucket '.$this->getBucket().' is now empty.</fg=green>');

        return true;
    }

    /**
     * This function will be called from the CdnFacade class when
     * someone use this {{ Cdn::asset('') }} facade helper.
     *
     * @param $path
     *
     * @return string
     */
    public function urlGenerator($path)
    {
        if ($this->getCloudFront() === true) {
            $url = $this->cdn_helper->parseUrl($this->getCloudFrontUrl());

            return $url['scheme'] . '://' . $url['host'] . '/' . $path;
        }

        $url = $this->cdn_helper->parseUrl($this->getUrl());

        $bucket = $this->getBucket();

        if ($this->supplier['use_path_style_endpoint']) {
            $bucket = (!empty($bucket)) ? $bucket.'/' : '';
            if (isset($url['port'])) {
                $port = (
                    ($url['scheme'] == 'https' && $url['port'] == 443) ||
                    ($url['scheme'] == 'http' && $url['port'] == 80)
                ) ? '' : ':' . $url['port'];
            } else {
                $port = '';
            }
            return $url['scheme'] . '://' .  $url['host'] . $port . '/' . $bucket . $path;
        }

        $bucket = (!empty($bucket)) ? $bucket.'.' : '';
        return $url['scheme'] . '://' . $bucket . $url['host'] . '/' . $path;
    }

    /**
     * @return string
     */
    public function getCloudFront()
    {
        if (!is_bool($cloudfront = $this->cloudfront)) {
            return false;
        }

        return $cloudfront;
    }

    /**
     * @return string
     */
    public function getCloudFrontUrl()
    {
        return rtrim($this->cloudfront_url, '/').'/';
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return rtrim($this->provider_url, '/') . '/';
    }
    
    /**
     * Calculate Amazon AWS ETag used on the S3 service
     *
     * @see https://stackoverflow.com/a/36072294/1762839
     * @author TheStoryCoder (https://stackoverflow.com/users/2404541/thestorycoder)
     *
     * @param string $filename path to file to check
     * @param int $chunksize chunk size in Megabytes
     * @param bool|string $expected verify calculated etag against this specified
     *                              etag and return true or false instead if you make
     *                              chunksize negative (eg. -8 instead of 8) the function
     *                              will guess the chunksize by checking all possible sizes
     *                              given the number of parts mentioned in $expected
     *
     * @return bool|string          ETag (string) or boolean true|false if $expected is set
     */
    protected function calculateEtag($filename, $chunksize, $expected = false) {
        if ($chunksize < 0) {
            $do_guess = true;
            $chunksize = 0 - $chunksize;
        } else {
            $do_guess = false;
        }

        $chunkbytes = $chunksize*1024*1024;
        $filesize = filesize($filename);
        if ($filesize < $chunkbytes && (!$expected || !preg_match("/^\\w{32}-\\w+$/", $expected))) {
            $return = md5_file($filename);
            if ($expected) {
                $expected = strtolower($expected);
                return ($expected === $return ? true : false);
            } else {
                return $return;
            }
        } else {
            $md5s = array();
            $handle = fopen($filename, 'rb');
            if ($handle === false) {
                return false;
            }
            while (!feof($handle)) {
                $buffer = fread($handle, $chunkbytes);
                $md5s[] = md5($buffer);
                unset($buffer);
            }
            fclose($handle);

            $concat = '';
            foreach ($md5s as $indx => $md5) {
                $concat .= hex2bin($md5);
            }
            $return = md5($concat) .'-'. count($md5s);
            if ($expected) {
                $expected = strtolower($expected);
                $matches = ($expected === $return ? true : false);
                if ($matches || $do_guess == false || strlen($expected) == 32) {
                    return $matches;
                } else {
                    // Guess the chunk size
                    preg_match("/-(\\d+)$/", $expected, $match);
                    $parts = $match[1];
                    $min_chunk = ceil($filesize / $parts /1024/1024);
                    $max_chunk =  floor($filesize / ($parts-1) /1024/1024);
                    $found_match = false;
                    for ($i = $min_chunk; $i <= $max_chunk; $i++) {
                        if ($this->calculateEtag($filename, $i) === $expected) {
                            $found_match = true;
                            break;
                        }
                    }
                    return $found_match;
                }
            } else {
                return $return;
            }
        }
    }


    /**
     * @param $attr
     *
     * @return Mix | null
     */
    public function __get($attr)
    {
        return isset($this->supplier[$attr]) ? $this->supplier[$attr] : null;
    }

    /**
     * Does file needs compression
     *
     * @param SplFileInfo $file File info
     *
     * @return bool
     */
    private function needCompress(SplFileInfo $file) {
        return !empty($this->compression['algorithm']) &&
            !empty($this->compression['extensions']) &&
            in_array($this->compression['algorithm'], ['gzip', 'deflate']) &&
            in_array('.' . $file->getExtension(), $this->compression['extensions']);
    }

    /**
     * Read file content and compress
     *
     * @param SplFileInfo $file             File to read
     * @param bool        $needsCompress    Need file to compress
     *
     * @return resource|string
     */
    private function getFileContent(SplFileInfo $file, $needsCompress) {
        if ($needsCompress) {
            switch ($this->compression['algorithm']) {
                case 'gzip':
                    return gzcompress(
                        file_get_contents(
                            $file->getRealPath()
                        ),
                        (int)$this->compression['level'],
                        ZLIB_ENCODING_GZIP
                    );
                case 'deflate':
                    return gzcompress(
                        file_get_contents(
                            $file->getRealPath()
                        ),
                        (int)$this->compression['level'],
                        ZLIB_ENCODING_DEFLATE
                    );
            }
        }
        return fopen($file->getRealPath(), 'r');
    }

    /**
     * Get mimetype from config or from system
     *
     * @param SplFileInfo $file File info to get mimetype
     *
     * @return false|string
     */
    protected function getMimetype(SplFileInfo $file) {
        $ext = '.' . $file->getExtension();
        if (isset($this->mimetypes[$ext])) {
            return $this->mimetypes[$ext];
        }
        return File::mimeType($file->getRealPath());
    }

}
