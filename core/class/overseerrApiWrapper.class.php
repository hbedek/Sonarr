<?php

require_once __DIR__  . '/overseerrApi.class.php';
require_once __DIR__  . '/sonarrUtils.class.php';
require_once __DIR__  . '/Utils/LogSonarr.php';
require_once __DIR__  . '/sonarrApiWrapper.class.php';
require_once __DIR__  . '/tmdbApi.class.php';

class overseerrApiWrapper
{
    protected $overseerrApi;
    protected $tmdbApi;
    protected $utils;

    public function __construct($url, $apiKey)
    {
        if ($url == NULL || $url == "") {
            LogSonarr::error('No URL given, this plugin needs the URL to your Overseerr to work');
        }
        if ($apiKey == NULL || $apiKey == "") {
            LogSonarr::error('No API KEY given, this plugin needs the API KEY of your Overseerr to work');
        }
        $this->overseerrApi = new overseerrApi($url, $apiKey);
        $this->tmdbApi = new tmdbApi('https://api.themoviedb.org', 'eyJhbGciOiJIUzI1NiJ9.eyJhdWQiOiI3Zjc1ODMyMDM4MmE2ZDFmNjhmYjYyMWQwOTZkYzMwZCIsIm5iZiI6MTYxODI2NzExNS4yODMsInN1YiI6IjYwNzRjYmViNGQwZThkMDAyYzU4YWQ1MSIsInNjb3BlcyI6WyJhcGlfcmVhZCJdLCJ2ZXJzaW9uIjoxfQ.nf_E5OCi-HYs4-sm76IdpbtNxCx3EmGsnYkddweJmsw');
        $this->utils = new sonarrUtils();
    }

    public function refreshOverseerr($context)
    {
        LogSonarr::info('start REFRESH OVERSEERR');
        $separator = $context->getSeparator();
        LogSonarr::info('selected separator: ' . $separator);

        LogSonarr::info('getting pending requests, will look for selected rule');
        $pendingRules = $context->getConfigurationFor($context, "dayPending", "maxPending");
        LogSonarr::debug('Tonio - my rule is '.$pendingRules['numberMax']);

        $this->getFormattedList($context, $separator, $pendingRules); // Tonio Test en cours

        LogSonarr::info('getting approved requests, will look for selected rule');
        // $approvedRules = $context->getConfigurationFor($context, "dayApproved", "maxApproved"); //ATTENTION N'existait pas en missingMovies
        // $this->getApprovedFormattedList($context, $separator); // Tonio Test en cours

        LogSonarr::info('getting declined requests, will look for selected rules');
        // $declinedRules = $context->getConfigurationFor($context, "dayDeclined", "maxDeclined");
        // $this->getDeclinedFormattedList($context, $declinedRules, $separator); // Tonio Test en cours

        // LogSonarr::info('notify for last downloaded movies');
        // $last_refresh_date = $context->getCmd(null, 'last_episode')->getValueDate();
        // $this->notifyMovie('radarr', $last_refresh_date, $context);
        LogSonarr::info('Nettoyage des images OVERSEERR');
        Sonarr::cleanUnusedImages($context);
        LogSonarr::info('stop REFRESH OVERSEERR');
    }

    public function getFormattedList($context, $separator, $rules)
    {
        $pendingListStr = '';
        $approvedListStr = '';
        $pendingList = [];
        $approvedList = [];
        $requestList = $this->getRequestArray($rules);
        LogSonarr::info('Number of pending requests ' . count($requestList));
        LogSonarr::info('Tonio - my requests list ' . json_encode($requestList));
        // FORMAT LIST
        foreach ($requestList as $request) {
            if ($request['reqStatus'] == 1) {
                LogSonarr::info($request["title"] . ' is pending');
                $pendingListStr = $this->utils->formatList($pendingListStr, $request["title"], $separator);
                array_push($pendingList, $request);
            } else if ($request['reqStatus'] == 2 && $request['mediaStatus'] != 5 )
            {
                LogSonarr::info($request["title"] . ' is approved');
                $approvedListStr = $this->utils->formatList($approvedListStr, $request["title"], $separator);
                array_push($approvedList, $request);
            }
        }
        // SAVE RAW
        LogSonarr::info('mes pending requests : ' .json_encode($pendingList) );
        $context->checkAndUpdateCmd('day_pending_raw', json_encode($pendingList));
        LogSonarr::info('mes accepted requests : ' .json_encode($approvedList) );
        $context->checkAndUpdateCmd('day_approved_raw', json_encode($approvedList));

        if ($pendingListStr == "") {
            LogSonarr::info('no pending request');
        }
        LogSonarr::info('pending requests list: ' . $pendingListStr);
        $context->checkAndUpdateCmd('day_pending', $pendingListStr);
        $context->checkAndUpdateCmd('day_approved', $approvedListStr);

    }
    public function getRequestArray($rules)
    {
        $liste_media = [];
        // $dayFutures = $rules["numberDays"];
        // $currentDate = new DateTime();
        // $futureDate = new DateTime();
        // $futureDate->add(new DateInterval('P' . $dayFutures . 'D'));
        // $currentDate = $currentDate->format('Y-m-d');
        // $futureDate = $futureDate->format('Y-m-d');
        // $currentDateTimeStp = strtotime($currentDate);
        $nbMax=$rules['numberMax'];
        LogSonarr::debug('Tonio -getRequestArray- my max is '.$nbMax);

        // Server call
        LogSonarr::debug('fetching the last '.$nbMax.' pending requests');
        $response = $this->overseerrApi->getRequest(2*$nbMax);
        LogSonarr::debug('JSON FOR REQUEST' . $response);
        // return error if needed
        $response = $this->utils->verifyJson($response);
        LogSonarr::debug('JSON FOR REQUEST (after verifyJSON) : ' . count($response['results']));
        if ($response == NULL || count($response['results']) == 0) {
            LogSonarr::debug('Tonio Aucune réponse');
            return "";
        }
        //Analyze datas
        foreach ($response['results'] as $media) {
            // $compareDate = $media["digitalRelease"];
            // if ($compareDate == null) {
            //     $compareDate = $media["inCinemas"];
            // }
            // $compareDateTimeStp = strtotime($compareDate);
            // if ($compareDateTimeStp > $currentDateTimeStp) {
            //     $compareDateStr = $this->utils->formatDate($compareDate);
            //     $mediaToNotify = $media["title"];
            //     $mediasId = $media["id"];
            //     $downloaded = $media["hasFile"];

            //     $size = $media["sizeOnDisk"];
            //     if ($size != null && $size != 0) {
            //         $size = $this->utils->formatSize($size);
            //     } else {
            //         $size = "";
            //     }
            //     $quality = $media["movieFile"]["quality"]["quality"]["resolution"];
            //     if ($quality != null && $quality != 0) {
            //         $quality = $quality . "p";
            //     } else {
            //         $quality = "";
            //     }
                // $images = $media["images"];
                // $urlImage = "";
                // foreach ($images as $image) {
                //     if ($image["coverType"] == "poster") {
                //         $urlImage =  $image["remoteUrl"]; // modif Tonio : was "url"
                //     }
                // }
                // $this->saveImage($urlImage, $mediasId);
                $mediaType = $media["media"]["mediaType"];
                $mediaID ="";
                if ($mediaType == 'tv') {
                    $mediaID = $media["media"]["tvdbId"];
                } else if ($mediaType == 'movie') {
                    $mediaID = $media["media"]["tmdbId"];
                }
                $reqStatus=$media["status"];
                $mediaStatus=$media["media"]["status"];
                $requester=$media["requestedBy"]["plexUsername"];
                LogSonarr::debug('Tonio  - Avant l\'API TMDB ID : '.$mediaID);
                $tmdbInfo = $this->getDataFromTmdb($mediaType, $mediaID);
                LogSonarr::debug('Tonio  - retour d\'API TMDB : '.$tmdbInfo);
                if ($mediaType == 'tv') {
                    $title=$tmdbInfo["tv_results"][0]["name"];
                } else if ($mediaType == 'movie') {
                    $title=$tmdbInfo["title"];
                }
                $urlImage=$tmdbInfo["poster_path"];
                
                LogSonarr::debug('Tonio  - titre : '.$title.', mediaType : '.$mediaType.', mediaID : '.$mediaID.', Statut requête : '.$reqStatus.', statut média : '.$mediaStatus.', Requester : '.$requester.', urlPoster  :'.$urlImage);
                
                $mediaObj = array(
                    // 'title' => $mediaToNotify,
                    // 'image' => $urlImage,
                    // 'seriesId' => $mediasId,
                    // 'date' => $compareDateStr,
                    // 'downloaded' => $downloaded,
                    // 'size' => $size,
                    // 'quality' => $quality,
                    'mediaType' => $mediaType,
                    'mediaID' => $mediaID,
                    'reqStatus' => $reqStatus, //1 = pending, 2=accepted, 3
                    'mediaStatus' => $mediaStatus,
                    'requester' => $requester,
                    'title' => $title,
                    'img' => $urlImage
                );
                
                array_push($liste_media, $mediaObj);
        }
        // $liste_media = $this->utils->applyMaxRulesToArray($liste_media, $rules);
        return $liste_media;
    }
    public function getApprovedFormattedList($context, $separator)
    {
        $approvedListStr = "";
        $approvedList = $this->getApprovedArray(null);
        // SAVE RAW
        $context->checkAndUpdateCmd('day_approved_raw', json_encode($approvedList));
        // FORMAT LIST
        foreach ($approvedList as $approvedReq) {
            $approvedListStr = $this->utils->formatList($approvedListStr, $approvedReq["title"], $separator);
        }
        if ($approvedList == "") {
            LogSonarr::info('no approved requests');
        }
        $context->checkAndUpdateCmd('day_approved', $approvedListStr);
    }

    public function getApprovedArray($rules)
    {
        $liste_movie = [];
        $moviesJSON = $this->overseerrApi->getMovies();
        LogSonarr::debug('JSON FOR MOVIES' . $moviesJSON);
        // return error if needed
        $movies = $this->utils->verifyJson($moviesJSON);
        if ($movies == NULL) {
            return "";
        }
        // Analyze datas
        $approvedList = [];
        foreach ($movies as $movie) {
            if ($movie["status"] == "released" && $movie["hasFile"] == false && $movie["monitored"] == true) {
                //Episode is missing
                array_push($approvedList, $movie);
            }
        }
        if (empty($approvedList)) {
            return "";
        }
        // Now that we have find all the missing movies, we have to sort them
        function compare_movies($a, $b)
        {
            return strtotime($b["digitalRelease"]) - strtotime($a["digitalRelease"]);
        }
        usort($approvedList, "compare_movies");
        if ($rules != null) {
            $approvedList = $this->utils->applyMaxRulesToArray($approvedList, $rules);
        }
        foreach ($approvedList as $movie) {
            $movieToNotify = $movie["title"];
            $moviesId = $movie["id"];
            $ddl_date_str = $movie["digitalRelease"];
            $ddl_date_str = $this->utils->formatDate($ddl_date_str);

            $size = $movie["sizeOnDisk"];
            if ($size != null && $size != 0) {
                $size = $this->utils->formatSize($size);
            } else {
                $size = "";
            }
            $quality = $movie["movieFile"]["quality"]["quality"]["resolution"];
            if ($quality != null && $quality != 0) {
                $quality = $quality . "p";
            } else {
                $quality = "";
            }
            $images = $movie["images"];
            $urlImage = "";
            foreach ($images as $image) {
                if ($image["coverType"] == "poster") {
                    $urlImage =  $image["remoteUrl"];
                }
            }
            $this->saveImage($urlImage, $moviesId);
            $movieObj = array(
                'title' => $movieToNotify,
                'image' => $urlImage,
                'seriesId' => $moviesId,
                'date' => $ddl_date_str,
                'size' => $size,
                'quality' => $quality,
            );
            array_push($liste_movie, $movieObj);
        }
        return $liste_movie;
    }

    public function getDeclinedFormattedList($context, $rules, $separator)
    {
        $ddlMoviesList = $this->getDeclinedArray($rules);
        // SAVE RAW
        $context->checkAndUpdateCmd('day_ddl_movies_raw', json_encode($ddlMoviesList));
        // FORMAT LIST
        $listOnlyTitle = [];
        foreach ($ddlMoviesList as $ddlObj) {
            array_push($listOnlyTitle, $ddlObj['title']);
        }
        $declinedList = implode($separator, $listOnlyTitle);
        if ($declinedList == "") {
            LogSonarr::info('no downloaded movies');
        }
        $context->checkAndUpdateCmd('day_ddl_movies', $declinedList);
    }

    public function getDeclinedArray($rules)
    {
        $anteriorDate = $this->utils->getAnteriorDateForNumberDay($rules["numberDays"]);
        $ddlMoviesList = $this->getHistoryForDate($anteriorDate);
        $ddlMoviesList = $this->utils->applyMaxRulesToArray($ddlMoviesList, $rules);
        return $ddlMoviesList;
    }

    public function notifyMovie($caller, $last_refresh_date, $context)
    {
        LogSonarr::info('date last refresh : ' . $last_refresh_date);
        $last_refresh_date = strtotime($last_refresh_date);
        $list_moviesImgs = $this->getHistoryForDate($last_refresh_date);
        $this->utils->sendNotificationForTitleImgArray($caller, $list_moviesImgs, $context);
    }

    private function getHistoryForDate($last_refresh_date)
    {
        $liste_movies = [];
        $stopSearch = false;
        $pageToSearch = 1;
        while ($stopSearch == false) {
            $historyJSON = $this->radarrApi->getHistory($pageToSearch, 10, 'date', 'descending');
            LogSonarr::debug('JSON FOR HISTORY' . $historyJSON);
            $history = $this->utils->verifyJson($historyJSON);
            if ($history == NULL || empty($history['records'])) {
                LogSonarr::info("stop searching for movies");
                $stopSearch = true;
            }
            foreach ($history['records'] as $movie) {
                if ($stopSearch == false && $movie["eventType"] == "downloadFolderImported") {
                    $ddl_date_str = $movie["date"];
                    $ddl_date = strtotime($ddl_date_str);
                    if ($ddl_date > $last_refresh_date || $last_refresh_date == NULL) {
                        if ($last_refresh_date == NULL) {
                            LogSonarr::info('first run for notification');
                            $stopSearch = true;
                        }
                        $movieId = $movie["movieId"];
                        $ddl_date_str = $this->utils->formatDate($ddl_date_str);


                        $quality = $movie["quality"]["quality"]["resolution"];
                        if ($quality != null && $quality != 0) {
                            $quality = $quality . "p";
                        } else {
                            $quality = "";
                        }
                        $movieObj = array(
                            'seriesId' => $movieId,
                            'date' => $ddl_date_str,
                            'quality' => $quality,
                        );
                        $movieObj = $this->retrieveMovieInformation($movieId, $movieObj);
                        array_push($liste_movies, $movieObj);
                    } else {
                        LogSonarr::info("stop searching for new movies to notify");
                        $stopSearch = true;
                    }
                }
            }
            $pageToSearch++;
        }
        return $liste_movies;
    }

    private function retrieveMovieInformation($movieId, $movieToComplete)
    {
        $movieJSON = $this->radarrApi->getMovies($movieId);
        LogSonarr::debug('JSON FOR SPECIFIC MOVIE' . $movieJSON);
        $movie = new Movie(json_decode($movieJSON, true));
        $movieToComplete['title'] = $movie->title;
        $movieToComplete['image'] = $movie->image;
        $this->saveImage($movie->image, $movieId);
        $size = $movie->size;
        if ($size != null && $size != 0) {
            $size = $this->utils->formatSize($size);
        } else {
            $size = "";
        }
        $movieToComplete['size'] = $size;
        return $movieToComplete;
    }

    private function getDataFromTmdb($type, $ID)
    {//Pour iverseer dans $url on mettra le type (movie ou tv)
        $img = '/var/www/html/plugins/sonarr/core/template/dashboard/imgs/overseerr_' . $ID . '.jpg';
        $response = "";
        $urlImage = "";
        if ($type=='movie') 
            {
                LogSonarr::debug('On va chercher MOVIE ID : '.$ID);
                $response = $this->tmdbApi->getMovie($ID);
                LogSonarr::debug('JSON FOR REQUEST' . $response);
                // return error if needed
                $response = $this->utils->verifyJson($response);
                $urlImage = $response["poster_path"];
            }
        else if ($type=='tv')
            {
                LogSonarr::debug('On va chercher TV ID : '.$ID);
                // $searchType = 'tvdb';
                $response = $this->tmdbApi->getTV($ID);
                LogSonarr::debug('JSON FOR REQUEST' . $response);
                // return error if needed
                $response = $this->utils->verifyJson($response);
                $urlImage = $response["tv_results"][0]["poster_path"];
                LogSonarr::debug('MON URL D\'IMAGE TV ' . $urlImage);

            }
        else
            {return "";}

        if (file_exists($img)) {
            LogSonarr::debug('L\'image de '.$response["title"].$response["tv_results"][0]["name"].' existe déjà');

        } else if ($urlImage != '') {
            $url = 'https://image.tmdb.org/t/p/original'.$urlImage;
            file_put_contents($img, file_get_contents($url));
                
        }

        return $response;

    }

    public function searchForMovie($context, $queryTerms)
    {
        LogSonarr::info('----------------------------------');
        LogSonarr::info('START MOVIE SEARCH ' . $context->getName() . ' with terms: ' . $queryTerms);
        // Check needed cmd
        $searchResult = SonarrRadarrUtils::verifyCmd($context, 'search_result');
        $searchResultRaw = SonarrRadarrUtils::verifyCmd($context, 'search_result_raw');
        if ($searchResult == null || $searchResultRaw == null) {
            return;
        }
        // Retrieve JSON
        $listMoviesJSON = $this->radarrApi->getMoviesLookup($queryTerms);
        LogSonarr::debug('JSON FOR SEARCH ' . $listMoviesJSON);
        // Save RAW
        $movies = new MoviesSearch($listMoviesJSON);
        $searchResultRaw->event(json_encode($movies));
        // Format list
        $separator = $context->getSeparator();
        $moviesStr = '';
        foreach ($movies->movies as $movie) {
            $str = $movie->title;
            $moviesStr = $this->utils->formatList($moviesStr, $str, $separator);
        }
        if ($moviesStr == "") {
            LogSonarr::info('no search results');
        }
        $searchResult->event($moviesStr);
        LogSonarr::info('END MOVIE SEARCH ' . $context->getName());
        LogSonarr::info('----------------------------------');
    }

    public function getProfiles($context)
    {
        LogSonarr::info('----------------------------------');
        LogSonarr::info('START GETTING PROFILES MOVIES ' . $context->getName());
        $profilesResult = SonarrRadarrUtils::verifyCmd($context, 'profiles_result');
        $profilesResultRaw = SonarrRadarrUtils::verifyCmd($context, 'profiles_result_raw');
        if ($profilesResult == null || $profilesResultRaw == null) {
            return;
        }
        $listProfilesJSON = $this->radarrApi->getProfiles();
        LogSonarr::debug('JSON FOR PROFILES ' . $listProfilesJSON);
        $profiles = new Profiles($listProfilesJSON);
        $profilesResultRaw->event(json_encode($profiles));
        // Format list
        $separator = $context->getSeparator();
        $profilesStr = '';
        foreach ($profiles->profiles as $profile) {
            $profilesStr = $this->utils->formatList($profilesStr, $profile->name, $separator);
        }
        if ($profilesStr == "") {
            LogSonarr::info('no profiles');
        }
        $profilesResult->event($profilesStr);
        LogSonarr::info('END GETTING PROFILES MOVIES ' . $context->getName());
        LogSonarr::info('----------------------------------');
    }

    public function getPaths($context)
    {
        LogSonarr::info('----------------------------------');
        LogSonarr::info('START GETTING PATHS MOVIES ' . $context->getName());
        $pathResult = SonarrRadarrUtils::verifyCmd($context, 'path_result');
        $pathResultRaw = SonarrRadarrUtils::verifyCmd($context, 'path_result_raw');
        if ($pathResult == null || $pathResultRaw == null) {
            return;
        }
        $listPathsJSON = $this->radarrApi->getRootFolder();
        LogSonarr::debug('JSON FOR PROFILES ' . $listPathsJSON);
        $paths = new Paths($listPathsJSON);
        $pathResultRaw->event(json_encode($paths));
        // Format list
        $separator = $context->getSeparator();
        $pathsStr = '';
        foreach ($paths->paths as $path) {
            $pathsStr = $this->utils->formatList($pathsStr, $path->path, $separator);
        }
        if ($pathsStr == "") {
            LogSonarr::info('no paths');
        }
        $pathResult->event($pathsStr);
        LogSonarr::info('END GETTING PATHS MOVIES ' . $context->getName());
        LogSonarr::info('----------------------------------');
    }

    public function getRadarrTags($context)
    {
        LogSonarr::info('----------------------------------');
        LogSonarr::info('START GETTING TAGS MOVIES ' . $context->getName());
        $tagResult = SonarrRadarrUtils::verifyCmd($context, 'tags_result');
        $tagResultRaw = SonarrRadarrUtils::verifyCmd($context, 'tags_result_raw');
        if ($tagResult == null || $tagResultRaw == null) {
            return;
        }
        $listTagsJSON = $this->radarrApi->getTags();
        LogSonarr::debug('JSON FOR TAGS ' . $listTagsJSON);
        $tags = new Tags($listTagsJSON);
        $tagResultRaw->event(json_encode($tags));
        // Format list
        $separator = $context->getSeparator();
        $tagsStr = '';
        foreach ($tags->tags as $tag) {
            $tagsStr = $this->utils->formatList($tagsStr, $tag->label, $separator);
        }
        if ($tagsStr == "") {
            LogSonarr::info('no tags');
        }
        $tagResult->event($tagsStr);
        LogSonarr::info('END GETTING TAGS MOVIES ' . $context->getName());
        LogSonarr::info('----------------------------------');
    }

    public function searchMissing($context)
    {
        LogSonarr::info('----------------------------------');
        LogSonarr::info('START SEARCH MISSING MOVIES ' . $context->getName());
        $this->radarrApi->postCommand('MissingMoviesSearch');
        LogSonarr::info('STOP SEARCH MISSING ' . $context->getName());
        LogSonarr::info('----------------------------------');
    }

    public function addMovie($context, $_options)
    {
        LogSonarr::info('----------------------------------');
        LogSonarr::info('START ADDING MOVIE ' . $context->getName());
        $searchResultRawCmd = SonarrRadarrUtils::verifyCmd($context, 'search_result_raw');
        $profilesResultRawCmd = SonarrRadarrUtils::verifyCmd($context, 'profiles_result_raw');
        if ($searchResultRawCmd == null || $profilesResultRawCmd == null) {
            return;
        }
        // To add a serie we have to find the profile
        $json = $_options['message'];
        if ($json == null) {
            LogSonarr::error('NO message given, cannot ADD movie');
            return;
        }
        $arrayOption = new AddOptions($json);
        $movieTitle = $arrayOption->serie;
        $profileString = $arrayOption->profile;
        $path = $arrayOption->path;
        if ($movieTitle == null || $profileString == null || $path == null) {
            LogSonarr::error('NO movie or profile or path, cannot ADD movie');
            return;
        }
        // On récupère la série dans la liste
        $movieToAdd = null;
        $searchResultToConvert = json_decode($searchResultRawCmd->execCmd(), true)['movies'];
        $encodedMoviesRaw = new MoviesSearch(json_encode($searchResultToConvert));
        foreach ($encodedMoviesRaw->movies as $movie) {
            if ($movieTitle == $movie->title) {
                $movieToAdd = $movie;
            }
        }
        if ($movieToAdd == null) {
            // La série n'a pas été trouvée
            LogSonarr::error('CANNOT FOUND MOVIE TO ADD');
            return;
        }
        $profileToAdd = null;
        $encodedProfilesToConvert = json_decode($profilesResultRawCmd->execCmd(), true)['profiles'];
        $encodedProfilesRaw = new Profiles(json_encode($encodedProfilesToConvert));
        foreach ($encodedProfilesRaw->profiles as $profile) {
            if ($profileString == $profile->name) {
                $profileToAdd = $profile;
            }
        }
        if ($profileToAdd == null) {
            // La série n'a pas été trouvée
            LogSonarr::error('CANNOT FOUND PROFILE TO ADD');
            return;
        }
        // Check if optionnal parameters
        $tagsToAdd = [];
        $tagsWanted = $arrayOption->tags;
        $tagsToAdd = SonarrRadarrUtils::retrieveTagsFromCmd($context, $tagsWanted);
        $data = array(
            'tmdbId' => $movieToAdd->tmdbId,
            'title' => $movieToAdd->title,
            'qualityProfileId' => $profileToAdd->id,
            'titleSlug' => $movieToAdd->titleSlug,
            'images' => $movieToAdd->images,
            'rootFolderPath' => $path,
            'tags' => $tagsToAdd,
        );
        $response = $this->radarrApi->postMovie($data);
        LogSonarr::debug('JSON ADDING MOVIE ' . json_encode($response));
        LogSonarr::info('END ADDING MOVIE ' . $context->getName());
        LogSonarr::info('----------------------------------');
    }
}

// class MoviesSearch
// {
//     public $movies;

//     function __construct($dataJSON)
//     {
//         $data = json_decode($dataJSON, true);
//         if ($data != '' && $data != null) {
//             $array_movies = array();
//             foreach ($data as $value) {
//                 $movie = new Movie($value);
//                 array_push($array_movies, $movie);
//             }
//             $this->movies = $array_movies;
//         }
//     }
// }

// class Movie
// {
//     public $title;
//     public $image;
//     public $size;
//     public $tmdbId;
//     public $titleSlug;

//     function __construct($data)
//     {
//         if (isset($data['title']))
//             $this->title = $data['title'];

//         if (isset($data['images'])) {
//             $images = $data['images'];
//             $urlImage = "";
//             foreach ($images as $image) {
//                 if ($image["coverType"] == "poster") {
//                     $urlImage =  $image["remoteUrl"];
//                 }
//             }
//             $this->image = $urlImage;
//         }

//         if (isset($data['sizeOnDisk']))
//             $this->size = $data['sizeOnDisk'];
        
//         if (isset($data['tmdbId']))
//             $this->tmdbId = $data['tmdbId'];

//         if (isset($data['titleSlug']))
//             $this->titleSlug = $data['titleSlug'];
//     }
// }
