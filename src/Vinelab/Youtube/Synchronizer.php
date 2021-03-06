<?php

namespace Vinelab\Youtube;

use Vinelab\Youtube\Contracts\ApiInterface;
use Vinelab\Youtube\Contracts\ChannelInterface;
use Vinelab\Youtube\Contracts\SynchronizerInterface;
use Vinelab\Youtube\Exceptions\IncompatibleParametersException;

class Synchronizer implements SynchronizerInterface
{
    /**
     * The api instance.
     *
     * @var Vinelab\Youtube\Contracts\ApiInterface
     */
    protected $api;

    /**
     * $the YoutubeChannelInterface instance.
     *
     * @var Vinelab\Youtube\Contracts\YoutubeChannelInterface
     */
    protected $channel;

    /**
     * $data will store all the data
     * after we sync the channels and
     * videos.
     *
     * @var array
     */
    protected $data = [];

    /**
     * Create a new instance of the VideoSynchroniser.
     *
     * @param ApiInterface     $api
     * @param ChannelInterface $channel
     */
    public function __construct(ApiInterface $api, ChannelInterface $channel)
    {
        $this->api = $api;
        $this->channel = $channel;
    }

    /**
     * Sync the resources.
     *
     * in case a resource(video or channel or playlist) has been been deleted
     * a 'IncompatibleParametersException' will be thrown
     * which means that the existing data and the new one are not
     * compatible(different kind) because if the resource has been deleted
     * Null will be returned in the response.
     *
     * @param \Vinelab\Youtube\ResourceInterface $resource
     *
     * @internal param \Vinelab\Youtube\ResourceInterface $existing_data
     *
     * @return Channel|Video
     */
    public function sync($resource)
    {
        // getting the youtube related information from the resource (info such as 'youtube_id')
        $info = $resource->getYoutubeInfo();

        // sync playlist: Vinelab\Youtube\Playlist
        if ($resource instanceof YoutubePlaylistInterface) {
            $synced_at = (new \DateTime($resource->synced_at))->format('Y-m-d\TH:i:sP');

            // make the online request
            $response = $this->api->playlist($info['youtube_id'], $synced_at);

            // if nothing returned, make another call but without the $synced_at param
            if (count($response->videos->all()) == 0) {
                $response = $this->api->playlist($info['youtube_id']);
            }

            // sync the channel with the new data
            $this->setPlaylistData($response);

            // sync the playlist videos videos that needs to be synced
            return $this->syncVideos($resource, $response);
        }
        if ($resource instanceof YoutubeChannelInterface) {
            $synced_at = (new \DateTime($resource->synced_at))->format('Y-m-d\TH:i:sP');

            // make the online request
            $response = $this->api->channel($info['youtube_id'], $synced_at);

            // if nothing returned, make another call but without the $synced_at param
            if (count($response->videos->all()) == 0) {
                $response = $this->api->channel($info['youtube_id']);
            }

            // check if sync is enabled for a channel
//            if($this->syncable($resource))
//            {
                // sync the channel with the new data
                $this->setChannelData($response);
//            }

            // sync the channel videos videos that needs to be synced
            return $this->syncVideos($resource, $response);
        }   // sync single videos: Vinelab\Youtube\Video
        elseif ($resource instanceof YoutubeVideoInterface) {
            // make the online request
            $response = $this->api->video($info['youtube_id']);

            //check if sync if enabled for a video
            if ($this->syncable($resource)) {
                //check if the etags are not the same.
                //if so, set the data to be equal to the
                //response value and return true.
                if ($this->videoDiff($resource, $response)) {
                    return $response;
                }
            }
        } else {
            //this will be throw if the following conditions were satisfied:
            //1. video + channel has been passed to the Sync method.
            //2. two videos has been passed with one of them deleted.
            //notice that we will never have a condition where an existing resource's
            //value is null, because it will mean that the actual resource doesn't exist.
            throw new IncompatibleParametersException();
        }
    }

    /**
     * check if the video etags are different.
     *
     * @param Vinelab\Youtube\Video $resource
     * @param Vinelab\Youtube\Video $response
     *
     * @return Boolean
     */
    protected function videoDiff($resource, $response)
    {
        //if the etag is different and if sync is enabled.
        //then return the new video info.
        if ($resource->etag != $response->etag) {
            return true;
        }
        //if the etags are the same, this means that
        //there are no changes in the video.
        return false;
    }

    /**
     * Sync the channel without the videos.
     *
     * @param Channel $response
     */
    protected function setChannelData($response)
    {
        $this->data = $response;
    }

    /**
     * Sync the playlist without the videos.
     *
     * @param Playlist $response
     */
    protected function setPlaylistData($response)
    {
        $this->data = $response;
    }

    /**
     * Sync the videos inside the channel.
     *
     * @param $request from our code
     * @param $response from youtube
     *
     * @return \Vinelab\Youtube\VideoCollection
     */
    protected function syncVideos($request, $response)
    {
        $response_videos = $response->videos;

        $request_videos = $request->videos;

        // this will hold all the Video objects that needs to be returned
        $results_holder = new VideoCollection();

        foreach ($response_videos as $response_video) {
            foreach ($request_videos as $request_video) {
                // if the youtube video id doesn't not exist locally (means it's a new video on youtube)
                if ($this->are_different_videos($request_video, $response_video)) {
                    var_dump($request_video->title, $response_video->snippet['title']);

                    // add the youtube video to the result
                    $results_holder->push($response_video);
                } else {

                    // if the etag is the same (means video have not been updated locally or online)
                    if ($this->is_modified($request_video, $response_video)) {
                        // add the local video to the result
                        $results_holder->push($request_video);
                    } else {
                        // if the sync is enabled then add the youtube video to the result else add the local video
                        $results_holder->push($this->is_syncable($request_video) ? $response_video : $request_video);
                    }
                }
            }
        }

        $this->data->setVideos($results_holder);

        return $results_holder;
    }

    /**
     * @param $request_video model from the client code
     * @param $response_video model from youtube parsed internally in this package
     *
     * @return bool
     */
    protected function are_different_videos($request_video, $response_video)
    {
        return ($request_video->getYoutubeInfo()['youtube_id'] != $response_video->getYoutubeInfo()['id']);
    }

    /**
     * @param $request_video model from the client code
     * @param $response_video model from youtube parsed internally in this package
     *
     * @return bool
     */
    protected function is_modified($request_video, $response_video)
    {
        return $request_video->getYoutubeInfo()['etag'] == $response_video->getYoutubeInfo()['etag'];
    }

    /**
     * @param $video
     *
     * @return mixed
     */
    protected function is_syncable($video)
    {
        return $video->sync_enabled;
    }

    /**
     * return the value of sync_enabled.
     *
     * @param Channel|Video $data
     *
     * @return bool
     */
    protected function syncable($data)
    {
        return $data->sync_enabled;
    }

    /**
     * return the youtube channel if.
     *
     * @return interger
     */
    public function getYoutubeId()
    {
        return $this->data['id'];
    }
}
