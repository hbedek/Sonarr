<?php

require_once __DIR__  . '/radarrApi.class.php';
require_once __DIR__  . '/sonarrUtils.class.php';
require_once __DIR__  . '/Utils/LogSonarr.php';
require_once __DIR__  . '/sonarrApiWrapper.class.php';

class radarrApiWrapper
{
    protected $radarrApi;
    protected $utils;

    public function __construct($url, $apiKey)
    {
        if ($url == NULL || $url == "") {
            LogSonarr::error('No URL given, this plugin needs the URL to your Radarr to work');
        }
        if ($apiKey == NULL || $apiKey == "") {
            LogSonarr::error('No API KEY given, this plugin needs the API KEY of your Radarr to work');
        }
        $this->radarrApi = new radarrApi($url, $apiKey);
        $this->utils = new sonarrUtils();
    }

    public function refreshRadarr($context)
    {
        LogSonarr::info('start REFRESH RADARR');
        $separator = $context->getSeparator();
        LogSonarr::info('selected separator: ' . $separator);

        LogSonarr::info('getting futures movies, will look for selected rule');
        $futurMoviesRules = $context->getConfigurationFor($context, "dayFutureMovies", "maxFutureMovies");
        $this->getFutureMoviesFormattedList($context, $separator, $futurMoviesRules);

        LogSonarr::info('getting missings movies');
        $this->getMissingMoviesFormattedList($context, $separator);

        LogSonarr::info('getting last downloaded movies, will look for selected rules');
        $downloadMoviesRules = $context->getConfigurationFor($context, "dayDownloadedMovies", "maxDownloadedMovies");
        $this->getDownladedMoviesFormattedList($context, $downloadMoviesRules, $separator);

        LogSonarr::info('notify for last downloaded movies');
        $last_refresh_date = $context->getCmd(null, 'last_episode')->getValueDate();
        $this->notifyMovie('radarr', $last_refresh_date, $context);
        LogSonarr::info('stop REFRESH RADARR');
    }

    public function getFutureMoviesFormattedList($context, $separator, $rules)
    {
        $futurMoviesListStr = '';
        $futurMoviesList = $this->getFutureMoviesArray($rules);
        LogSonarr::info('Number of futur movies' . count($futurMoviesList));
        // SAVE RAW
        $context->checkAndUpdateCmd('day_movies_raw', json_encode($futurMoviesList));
        // FORMAT LIST
        foreach ($futurMoviesList as $futurMovie) {
            LogSonarr::info($futurMovie["title"] . ' is missing');
            $futurMoviesListStr = $this->utils->formatList($futurMoviesListStr, $futurMovie["title"], $separator);
        }
        if ($futurMoviesListStr == "") {
            LogSonarr::info('no future movies');
        }
        LogSonarr::info('futur movie list: ' . $futurMoviesListStr);
        $context->checkAndUpdateCmd('day_movies', $futurMoviesListStr);
    }
    public function getFutureMoviesArray($rules)
    {
        $liste_movie = [];
        $dayFutures = $rules["numberDays"];
        $currentDate = new DateTime();
        $futureDate = new DateTime();
        $futureDate->add(new DateInterval('P' . $dayFutures . 'D'));
        $currentDate = $currentDate->format('Y-m-d');
        $futureDate = $futureDate->format('Y-m-d');
        $currentDateTimeStp = strtotime($currentDate);
        // Server call
        LogSonarr::debug('fetching futures movies between ' . $currentDate . ' and ' . $futureDate);
        $calendar = $this->radarrApi->getCalendar($currentDate, $futureDate);
        LogSonarr::debug('JSON FOR CALENDAR' . $calendar);
        // return error if needed
        $calendar = $this->utils->verifyJson($calendar);
        if ($calendar == NULL) {
            return "";
        }
        //Analyze datas
        foreach ($calendar as $movie) {
            $compareDate = $movie["digitalRelease"];
            if ($compareDate == null) {
                $compareDate = $movie["inCinemas"];
            }
            $compareDateTimeStp = strtotime($compareDate);
            if ($compareDateTimeStp > $currentDateTimeStp) {
                $compareDateStr = $this->utils->formatDate($compareDate);
                $movieToNotify = $movie["title"];
                $moviesId = $movie["id"];
                $downloaded = $movie["hasFile"];

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
                        $urlImage =  $image["remoteUrl"];     //modif Tonio : was "url" https://community.jeedom.com/t/retours-sur-test-plugin/132675/3
                    }
                }
                $this->saveImage($urlImage, $moviesId);
                $movieObj = array(
                    'title' => $movieToNotify,
                    'image' => $urlImage,
                    'seriesId' => $moviesId,
                    'date' => $compareDateStr,
                    'downloaded' => $downloaded,
                    'size' => $size,
                    'quality' => $quality,
                );
                array_push($liste_movie, $movieObj);
            }
        }
        $liste_movie = $this->utils->applyMaxRulesToArray($liste_movie, $rules);
        return $liste_movie;
    }
    public function getMissingMoviesFormattedList($context, $separator)
    {
        $missingMoviesListStr = "";
        $missingMoviesList = $this->getMissingMoviesArray(null);
        // SAVE RAW
        $context->checkAndUpdateCmd('day_missing_movies_raw', json_encode($missingMoviesList));
        // FORMAT LIST
        foreach ($missingMoviesList as $missingMovie) {
            $missingMoviesListStr = $this->utils->formatList($missingMoviesListStr, $missingMovie["title"], $separator);
        }
        if ($missingMoviesList == "") {
            LogSonarr::info('no missing movies');
        }
        $context->checkAndUpdateCmd('day_missing_movies', $missingMoviesList);
    }

    public function getMissingMoviesArray($rules)
    {
        $liste_movie = [];
        $moviesJSON = $this->radarrApi->getMovies();
        LogSonarr::debug('JSON FOR MOVIES' . $moviesJSON);
        // return error if needed
        $movies = $this->utils->verifyJson($moviesJSON);
        if ($movies == NULL) {
            return "";
        }
        // Analyze datas
        $missingMoviesList = [];
        foreach ($movies as $movie) {
            if ($movie["status"] == "released" && $movie["hasFile"] == false && $movie["monitored"] == true) {
                //Episode is missing
                array_push($missingMoviesList, $movie);
            }
        }
        if (empty($missingMoviesList)) {
            return "";
        }
        // Now that we have find all the missing movies, we have to sort them
        function compare_movies($a, $b)
        {
            return strtotime($b["digitalRelease"]) - strtotime($a["digitalRelease"]);
        }
        usort($missingMoviesList, "compare_movies");
        if ($rules != null) {
            $missingMoviesList = $this->utils->applyMaxRulesToArray($missingMoviesList, $rules);
        }
        foreach ($missingMoviesList as $movie) {
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

    public function getDownladedMoviesFormattedList($context, $rules, $separator)
    {
        $ddlMoviesList = $this->getDownloadedMoviesArray($rules);
        // SAVE RAW
        $context->checkAndUpdateCmd('day_ddl_movies_raw', json_encode($ddlMoviesList));
        // FORMAT LIST
        $listOnlyTitle = [];
        foreach ($ddlMoviesList as $ddlObj) {
            array_push($listOnlyTitle, $ddlObj['title']);
        }
        $downloadMoviesList = implode($separator, $listOnlyTitle);
        if ($downloadMoviesList == "") {
            LogSonarr::info('no downloaded movies');
        }
        $context->checkAndUpdateCmd('day_ddl_movies', $downloadMoviesList);
    }

    public function getDownloadedMoviesArray($rules)
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

    private function saveImage($url, $imageName)
    {
        $img = '/var/www/html/plugins/sonarr/core/template/dashboard/imgs/radarr_' . $imageName . '.jpg';
        file_put_contents($img, file_get_contents($url));
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

class MoviesSearch
{
    public $movies;

    function __construct($dataJSON)
    {
        $data = json_decode($dataJSON, true);
        if ($data != '' && $data != null) {
            $array_movies = array();
            foreach ($data as $value) {
                $movie = new Movie($value);
                array_push($array_movies, $movie);
            }
            $this->movies = $array_movies;
        }
    }
}

class Movie
{
    public $title;
    public $image;
    public $size;
    public $tmdbId;
    public $titleSlug;

    function __construct($data)
    {
        if (isset($data['title']))
            $this->title = $data['title'];

        if (isset($data['images'])) {
            $images = $data['images'];
            $urlImage = "";
            foreach ($images as $image) {
                if ($image["coverType"] == "poster") {
                    $urlImage =  $image["remoteUrl"];
                }
            }
            $this->image = $urlImage;
        }

        if (isset($data['sizeOnDisk']))
            $this->size = $data['sizeOnDisk'];
        
        if (isset($data['tmdbId']))
            $this->tmdbId = $data['tmdbId'];

        if (isset($data['titleSlug']))
            $this->titleSlug = $data['titleSlug'];
    }
}
