<?php

require_once __DIR__  . '/sonarrApi.class.php';
require_once __DIR__  . '/sonarrUtils.class.php';
require_once __DIR__  . '/Utils/LogSonarr.php';
require_once __DIR__  . '/Utils/SonarrRadarrUtils.php';


class sonarrApiWrapper
{
    protected $sonarrApi;
    protected $utils;

    public function __construct($url, $apiKey)
    {
        if ($url == NULL || $url == "") {
            LogSonarr::error('No URL given, this plugin needs the URL to your Sonarr to work');
        }
        if ($apiKey == NULL || $apiKey == "") {
            LogSonarr::error('No API KEY given, this plugin needs the API KEY of your Sonarr to work');
        }
        $this->sonarrApi = new sonarrApi($url, $apiKey);
        $this->utils = new sonarrUtils();
    }

    public function refreshSonarr($context)
    {
        LogSonarr::info('start REFRESH SONARR');
        $separator = $context->getSeparator();
        LogSonarr::info('selected separator: ' . $separator);
        $formattor = $context->getConfiguration('formattorEpisode');
        LogSonarr::info('selected formattor: ' . $formattor);
        LogSonarr::info('getting futures episodes, will look for selected rule');
        $futurEpisodesRules = $context->getConfigurationFor($context, "dayFutureEpisodes", "maxFutureEpisodes");
        $this->getFutureEpisodesFormattedList($context, $separator, $futurEpisodesRules, $formattor);
        LogSonarr::info('getting missings episodes, will look for selected rule');
        $missingEpisodesRules = $context->getConfigurationFor($context, "dayMissingEpisodes", "maxMissingEpisodes");
        $this->getMissingEpisodesFormattedList($context, $missingEpisodesRules, $separator, $formattor);
        LogSonarr::info('getting last downloaded episodes, will look for specific rules');
        $groupEpisode = $context->getConfiguration('groupedEpisodes');
        $separatorEpisodes = $this->getSeparatorEpisodes($context);
        $downloadedEpisodesRules = $context->getConfigurationFor($context, "dayDownloadedEpisodes", "maxDownloadedEpisodes");
        $this->getDownladedEpisodesFormattedList($context, $downloadedEpisodesRules, $separator, $formattor, $groupEpisode, $separatorEpisodes);
        LogSonarr::info('notify for last downloaded episodes');
        $last_refresh_date = $context->getCmd(null, 'notificationHTML')->getValueDate();
        $this->notifyEpisode('sonarr', $last_refresh_date, $context, $formattor, $groupEpisode, $separatorEpisodes);
        LogSonarr::info('getting the monitored series');
        $liste_monitored_series = $this->getMonitoredSeries($separator);
        if ($liste_monitored_series == "") {
            LogSonarr::info('no monitored series');
        } else {
            $context->checkAndUpdateCmd('monitoredSeries', $liste_monitored_series);
        }
        LogSonarr::info('stop REFRESH SONARR');
    }
    public function getSeparatorEpisodes($context)
    {
        $separator = $context->getConfiguration('separatorEpisodes');
        if ($separator != NULL) {
            return $separator;
        } else {
            return ", ";
        }
    }

    public function getFutureEpisodesFormattedList($context, $separator, $rules, $formattor)
    {
        $futurEpisodesList = $this->getFutureEpisodesArray($rules, $formattor);
        // Save RAW
        $context->checkAndUpdateCmd('day_episodes_raw', json_encode($futurEpisodesList));
        // Format list
        $futurEpisodesListStr = '';
        foreach ($futurEpisodesList as $futurEpisode) {
            $futurEpisodesListStr = $this->utils->formatList($futurEpisodesListStr, $futurEpisode["title"], $separator);
        }
        if ($futurEpisodesListStr == "") {
            LogSonarr::info('no future episodes');
        }
        // Save formatted list
        $context->checkAndUpdateCmd('day_episodes', $futurEpisodesListStr);
    }
    private function getFutureEpisodesArray($rules, $formattor)
    {
        $liste_episode = [];
        $dayFutures = $rules["numberDays"];
        $currentDate = new DateTime();
        $futureDate = new DateTime();
        $futureDate->add(new DateInterval('P' . $dayFutures . 'D'));
        $currentDate = $currentDate->format('Y-m-d');
        $futureDate = $futureDate->format('Y-m-d');
        // Server call
        LogSonarr::debug('fetching futures episodes between ' . $currentDate . ' and ' . $futureDate);
        $calendar = $this->sonarrApi->getCalendar($currentDate, $futureDate);
        LogSonarr::debug('JSON FOR CALENDAR' . $calendar);
        // return error if needed
        $calendar = $this->utils->verifyJson($calendar);
        if ($calendar == NULL) {
            return "";
        }
        $calendar = $this->utils->applyMaxRulesToArray($calendar, $rules);
        // Analyze datas
        foreach ($calendar as $serie) {
            $episodeTitle = $serie["series"]["title"];
            $seasonNumber = $serie["seasonNumber"];
            $episodeNumber = $serie["episodeNumber"];
            $episode = $this->utils->formatEpisode($episodeTitle, $seasonNumber, $episodeNumber, $formattor);
            $seriesId = $serie["seriesId"];
            $ddl_date_str = $serie["airDateUtc"];
            $ddl_date_str = $this->utils->formatDate($ddl_date_str);
            $images = $serie["series"]["images"];
            $urlImage = "";
            foreach ($images as $image) {
                if ($image["coverType"] == "poster") {
                    $urlImage =  $image["remoteUrl"];
                }
            }
            //Save image
            $this->saveImage($urlImage, $seriesId);
            $downloaded = $serie["hasFile"];
            $size = $serie["episodeFile"]["size"];
            if ($size != null && $size != 0) {
                $size = $this->utils->formatSize($size);
            } else {
                $size = "";
            }
            $quality = $serie["episodeFile"]["quality"]["quality"]["resolution"];
            if ($quality != null && $quality != 0) {
                $quality = $quality . "p";
            } else {
                $quality = "";
            }
            $episodeImage = array(
                'title' => $episode,
                'serie' => $episodeTitle,
                'image' => $urlImage,
                'seriesId' => $seriesId,
                'date' => $ddl_date_str,
                'downloaded' => $downloaded,
                'size' => $size,
                'quality' => $quality,
            );
            array_push($liste_episode, $episodeImage);
        }
        return $liste_episode;
    }
    public function getMissingEpisodesFormattedList($context, $rules, $separator, $formattor)
    {
        $missingEpisodesList = $this->getMissingEpisodesArray($rules, $formattor);
        // Save RAW
        $context->checkAndUpdateCmd('day_missing_episodes_raw', json_encode($missingEpisodesList));
        // Format list
        $missingEpisodesListStr = "";
        foreach ($missingEpisodesList as $missingEpisode) {
            $missingEpisodesListStr = $this->utils->formatList($missingEpisodesListStr, $missingEpisode["title"], $separator);
        }
        if ($missingEpisodesListStr == "") {
            LogSonarr::info('no missing episodes');
        }
        // Save format
        $context->checkAndUpdateCmd('day_missing_episodes', $missingEpisodesListStr);
    }
    public function getMissingEpisodesArray($rules, $formattor)
    {
        $missingEpisodesList = [];
        $stopSearch = false;
        $pageToSearch = 1;
        while ($stopSearch == false) {
            $missingEpisodesJSON = $this->sonarrApi->getWantedMissing($pageToSearch, 10, 'airDateUtc', 'desc');
            LogSonarr::debug('JSON FOR MISSINGS' . $missingEpisodesJSON);
            $missingEpisodes = $this->utils->verifyJson($missingEpisodesJSON);
            if ($missingEpisodes == NULL || empty($missingEpisodes['records'])) {
                LogSonarr::info("stop searching for missing episodes, no more episodes");
                $stopSearch = true;
            }
            foreach ($missingEpisodes['records'] as $serie) {
                $episodeTitle = $serie["series"]["title"];
                // Verify date rule
                $numberDaysToRetrieve = $rules["numberDays"];
                $anteriorDate = $this->utils->getAnteriorDateForNumberDay($numberDaysToRetrieve);
                $airDateEpisode = strtotime($serie["airDateUtc"]);
                if ($stopSearch == false && $airDateEpisode > $anteriorDate) {
                    // We can add the episode
                    $seriesId = $serie["seriesId"];
                    $seasonNumber = $serie["seasonNumber"];
                    $episodeNumber = $serie["episodeNumber"];
                    $episode = $this->utils->formatEpisode($episodeTitle, $seasonNumber, $episodeNumber, $formattor);
                    $ddl_date_str = $serie["airDateUtc"];
                    $ddl_date_str = $this->utils->formatDate($ddl_date_str);
                    $images = $serie["series"]["images"];
                    $urlImage = "";
                    foreach ($images as $image) {
                        if ($image["coverType"] == "poster") {
                            $urlImage =  $image["remoteUrl"];
                        }
                    }
                    //Save image
                    $this->saveImage($urlImage, $seriesId);
                    $episodeImage = array(
                        'title' => $episode,
                        'serie' => $episodeTitle,
                        'image' => $urlImage,
                        'seriesId' => $seriesId,
                        'date' => $ddl_date_str,
                    );
                    array_push($missingEpisodesList, $episodeImage);
                } else if ($stopSearch == false) {
                    $stopSearch = true;
                }
            }
            $pageToSearch++;
        }
        $missingEpisodesList = $this->utils->applyMaxRulesToArray($missingEpisodesList, $rules);
        return $missingEpisodesList;
    }
    public function getDownladedEpisodesFormattedList($context, $rules, $separator, $formattor, $groupEpisode, $separatorEpisodes)
    {
        LogSonarr::info('---------------------------');
        LogSonarr::info('START GET DDL EPISODES');
        $ddlEpisodesList = $this->getDownloadedEpisodesArray($rules);
        $ddlEpisodesList = sonarrUtils::getEpisodesMoviesList($ddlEpisodesList, $groupEpisode, $formattor, $separatorEpisodes);
        // Save RAW
        $context->checkAndUpdateCmd('day_ddl_episodes_raw', json_encode($ddlEpisodesList));
        // Format list
        $listOnlyTitle = [];
        foreach ($ddlEpisodesList as $ddlObj) {
            array_push($listOnlyTitle, $ddlObj['title']);
        }
        $dowloadedEpisodesList =  implode($separator, $listOnlyTitle);
        if ($dowloadedEpisodesList == "") {
            LogSonarr::info('no downloaded episodes');
        }
        // Save format
        $context->checkAndUpdateCmd('day_ddl_episodes', $dowloadedEpisodesList);
        LogSonarr::info('---------------------------');
        LogSonarr::info('END GET DDL EPISODES');
    }

    public function notifyEpisode($caller, $last_refresh_date, $context, $formattor, $groupEpisode, $separatorEpisodes)
    {
        LogSonarr::info('date du dernier refresh : ' . $last_refresh_date);
        $last_refresh_date = strtotime($last_refresh_date);
        $list_episodesImgs = $this->getHistoryForDate($last_refresh_date);
        $list_episodesImgs = sonarrUtils::getEpisodesMoviesList($list_episodesImgs, $groupEpisode, $formattor, $separatorEpisodes);
        $this->utils->sendNotificationForTitleImgArray($caller, $list_episodesImgs, $context, $formattor);
    }

    public function getDownloadedEpisodesArray($rules)
    {
        $anteriorDate = $this->utils->getAnteriorDateForNumberDay($rules["numberDays"]);
        $ddlEpisodesList = $this->getHistoryForDate($anteriorDate);
        $ddlEpisodesList = $this->utils->applyMaxRulesToArray($ddlEpisodesList, $rules);
        return $ddlEpisodesList;
    }

    private function getHistoryForDate($last_refresh_date)
    {
        $episodeList = [];
        $stopSearch = false;
        $pageToSearch = 1;
        while ($stopSearch == false) {
            $historyJSON = $this->sonarrApi->getHistory($pageToSearch, 10, 'date', 'desc');
            LogSonarr::debug('JSON FOR HISTORY' . $historyJSON);
            $history = $this->utils->verifyJson($historyJSON);
            if ($history == NULL || empty($history['records'])) {
                LogSonarr::info("stop searching for new episode to notify empty history page");
                $stopSearch = true;
            }
            foreach ($history['records'] as $serie) {
                if ($stopSearch == false && $serie["eventType"] == "downloadFolderImported") {
                    $ddl_date_str = $serie["date"];
                    $ddl_date = strtotime($ddl_date_str);
                    if ($ddl_date > $last_refresh_date || $last_refresh_date == NULL) {
                        if ($last_refresh_date == NULL) {
                            LogSonarr::info('first run for notification');
                            $stopSearch = true;
                        }
                        $seriesId = $serie["seriesId"];
                        $episodeTitle = $serie["series"]["title"];
                        $seasonNumber = $serie["episode"]["seasonNumber"];
                        $episodeNumber = $serie["episode"]["episodeNumber"];
                        $quality = $serie["quality"]["quality"]["resolution"] . "p";
                        $images = $serie["series"]["images"];
                        $urlImage = "";
                        foreach ($images as $image) {
                            if ($image["coverType"] == "poster") {
                                $urlImage =  $image["remoteUrl"];
                            }
                        }
                        //Save image
                        $this->saveImage($urlImage, $seriesId);
                        // We have to find specifics informations on the episode
                        $size = $this->retrieveSizeForEpisode($serie["episodeId"]);
                        //$missingEpisodeNumber = $this->retrieveNumberMissingEpForSerie($serie["seriesId"]);
                        $ddl_date_str = $this->utils->formatDate($ddl_date_str);
                        $episodeImage = array(
                            'serie' => $episodeTitle,
                            'season' => $seasonNumber,
                            'episode' => $episodeNumber,
                            'quality' => $quality,
                            'size' => $size,
                            'date' => $ddl_date_str,
                            'image' => $urlImage,
                            'seriesId' => $seriesId,
                        );
                        array_push($episodeList, $episodeImage);
                        LogSonarr::info("found new episode downladed :" . $serie["series"]["title"]);
                    } else {
                        LogSonarr::info("stop searching for new episode to notify");
                        $stopSearch = true;
                    }
                }
            }
            $pageToSearch++;
        }
        return $episodeList;
    }
    public function retrieveWidgetsDatas()
    {
    }
    private function retrieveSizeForEpisode($episodeId)
    {
        $informationsEpisode = $this->getInformationsEpisodes($episodeId);
        $sizeByte = $informationsEpisode["episodeFile"]["size"];
        return $this->utils->formatSize($sizeByte);
    }
    private function getInformationsEpisodes($episodeId)
    {
        $episodeInfoJSON = $this->sonarrApi->getEpisode($episodeId);
        LogSonarr::debug('JSON FOR SPECIFIC EPISODE' . $episodeInfoJSON);
        $episodeInfo = $this->utils->verifyJson($episodeInfoJSON);
        if ($episodeInfo == NULL) {
            return "";
        } else {
            return $episodeInfo;
        }
    }
    private function retrieveNumberMissingEpForSerie($seriesId)
    {
        $informationsSerie = $this->getInformationsSeries($seriesId);
        $numberEpisodeMissing = 0;
        foreach ($informationsSerie["seasons"] as $season) {
            if ($season["monitored"]) {
                $numberEpisodeMissing += $season["totalEpisodeCount"] - $season["episodeFileCount"];
            }
        }
        return $numberEpisodeMissing;
    }
    private function getInformationsSeries($seriesId)
    {
        $seriesInfoJSON = $this->sonarrApi->getSeries($seriesId);
        LogSonarr::debug('JSON FOR SPECIFIC SERIES' . $seriesInfoJSON);
        $seriesInfo = $this->utils->verifyJson($seriesInfoJSON);
        if ($seriesInfo == NULL) {
            return "";
        } else {
            return $seriesInfo;
        }
    }
    public function getMonitoredSeries($separator)
    {
        $listSeriesJSON = $this->sonarrApi->getSeries();
        LogSonarr::debug('JSON FOR SERIES ' . $listSeriesJSON);
        $listSeries = $this->utils->verifyJson($listSeriesJSON);
        if ($listSeries == NULL) {
            LogSonarr::info('There are nos series in your sonarr');
            return "";
        }
        $liste_series = "";
        foreach ($listSeries as $serie) {
            if ($serie["monitored"] == true) {
                $liste_series = $this->utils->formatList($liste_series, $serie["title"], $separator);
            }
        }
        return $liste_series;
    }

    public function getSystemStatus()
    {
        $statusJSON = $this->sonarrApi->getDiskspace();
        LogSonarr::info('JSON FOR DISKSPACE ' . $statusJSON);
    }

    private function saveImage($url, $imageName)
    {
        $img = '/var/www/html/plugins/sonarr/core/template/dashboard/imgs/sonarr_' . $imageName . '.jpg';
        file_put_contents($img, file_get_contents($url));
    }

    public function searchForSerie($context, $queryTerms)
    {
        LogSonarr::info('----------------------------------');
        LogSonarr::info('START SERIE SEARCH ' . $context->getName() . ' with terms: ' . $queryTerms);
        // Check needed cmd
        $searchResult = SonarrRadarrUtils::verifyCmd($context, 'search_result');
        $searchResultRaw = SonarrRadarrUtils::verifyCmd($context, 'search_result_raw');
        if ($searchResult == null || $searchResultRaw == null) {
            return;
        }
        // Retrieve JSON
        $listSeriesJSON = $this->sonarrApi->getSeriesLookup($queryTerms);
        LogSonarr::debug('JSON FOR SEARCH ' . $listSeriesJSON);
        // Save RAW
        $series = new SeriesSearch($listSeriesJSON);
        $searchResultRaw->event(json_encode($series));
        // Format list
        $separator = $context->getSeparator();
        $seriesStr = '';
        foreach ($series->series as $serie) {
            $str = $serie->title . ' ' . $serie->year;
            $seriesStr = $this->utils->formatList($seriesStr, $str, $separator);
        }
        if ($seriesStr == "") {
            LogSonarr::info('no search results');
        }
        $searchResult->event($seriesStr);
        LogSonarr::info('END SERIE SEARCH ' . $context->getName());
        LogSonarr::info('----------------------------------');
    }

    public function addSerie($context, $_options)
    {
        LogSonarr::info('----------------------------------');
        LogSonarr::info('START ADDING SERIE ' . $context->getName());
        $searchResultRawCmd = SonarrRadarrUtils::verifyCmd($context, 'search_result_raw');
        $profilesResultRawCmd = SonarrRadarrUtils::verifyCmd($context, 'profiles_result_raw');
        if ($searchResultRawCmd == null || $profilesResultRawCmd == null) {
            return;
        }
        // To add a serie we have to find the profile
        $json = $_options['message'];
        if ($json == null) {
            LogSonarr::error('NO message given, cannot ADD serie');
            return;
        }
        $arrayOption = new AddOptions($json);
        $serieTitle = $arrayOption->serie;
        $profileString = $arrayOption->profile;
        $path = $arrayOption->path;
        if ($serieTitle == null || $profileString == null || $path == null) {
            LogSonarr::error('NO serie or profile or path, cannot ADD serie');
            return;
        }
        // On récupère la série dans la liste
        $serieToAdd = null;
        $searchResultToConvert = json_decode($searchResultRawCmd->execCmd(), true)['series'];
        $encodedSeriesRaw = new SeriesSearch(json_encode($searchResultToConvert));
        foreach ($encodedSeriesRaw->series as $serie) {
            if ($serieTitle == $serie->title) {
                $serieToAdd = $serie;
            }
        }
        if ($serieToAdd == null) {
            // La série n'a pas été trouvée
            LogSonarr::error('CANNOT FOUND SERIE TO ADD');
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
        $seriesType = $this->retrieveSeriesType($arrayOption->seriesType);
        $monitoringType = $this->retrieveMonitoreOptions($arrayOption->monitoringType);
        $data = array(
            'tvdbId' => $serieToAdd->tvdbId,
            'title' => $serieToAdd->title,
            'qualityProfileId' => $profileToAdd->id,
            'titleSlug' => $serieToAdd->titleSlug,
            'images' => $serieToAdd->images,
            'seasons' => $serieToAdd->seasons,
            'rootFolderPath' => $path,
            'tags' => $tagsToAdd,
            'seriesType' => $seriesType,
            'monitoringType' => $monitoringType,
        );
        $response = $this->sonarrApi->postSeries($data);
        LogSonarr::debug('JSON ADDING SERIE ' . json_encode($response));
        LogSonarr::info('END ADDING SERIE ' . $context->getName());
        LogSonarr::info('----------------------------------');
    }
    public function getProfiles($context)
    {
        LogSonarr::info('----------------------------------');
        LogSonarr::info('START GETTING PROFILES SERIES ' . $context->getName());
        $profilesResult = SonarrRadarrUtils::verifyCmd($context, 'profiles_result');
        $profilesResultRaw = SonarrRadarrUtils::verifyCmd($context, 'profiles_result_raw');
        if ($profilesResult == null || $profilesResultRaw == null) {
            return;
        }
        $listProfilesJSON = $this->sonarrApi->getProfiles();
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
        LogSonarr::info('END GETTING PROFILES SERIES ' . $context->getName());
        LogSonarr::info('----------------------------------');
    }

    public function getPaths($context)
    {
        LogSonarr::info('----------------------------------');
        LogSonarr::info('START GETTING PATHS SERIES ' . $context->getName());
        $pathResult = SonarrRadarrUtils::verifyCmd($context, 'path_result');
        $pathResultRaw = SonarrRadarrUtils::verifyCmd($context, 'path_result_raw');
        if ($pathResult == null || $pathResultRaw == null) {
            return;
        }
        $listPathsJSON = $this->sonarrApi->getRootFolder();
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
        LogSonarr::info('END GETTING PATHS SERIES ' . $context->getName());
        LogSonarr::info('----------------------------------');
    }

    public function getSonarrTags($context)
    {
        LogSonarr::info('----------------------------------');
        LogSonarr::info('START GETTING TAGS SERIES ' . $context->getName());
        $tagResult = SonarrRadarrUtils::verifyCmd($context, 'tags_result');
        $tagResultRaw = SonarrRadarrUtils::verifyCmd($context, 'tags_result_raw');
        if ($tagResult == null || $tagResultRaw == null) {
            return;
        }
        $listTagsJSON = $this->sonarrApi->getTags();
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
        LogSonarr::info('END GETTING TAGS SERIES ' . $context->getName());
        LogSonarr::info('----------------------------------');
    }

    public function searchMissing($context)
    {
        LogSonarr::info('----------------------------------');
        LogSonarr::info('START SEARCH MISSING SERIES ' . $context->getName());
        $this->sonarrApi->postCommand('missingEpisodeSearch');
        LogSonarr::info('STOP SEARCH MISSING SERIES ' . $context->getName());
        LogSonarr::info('----------------------------------');
    }

    public function retrieveSeriesType($seriesTypeWanted)
    {
        if (
            $seriesTypeWanted != '' &&
            $seriesTypeWanted != 'standard' &&
            $seriesTypeWanted != 'daily' &&
            $seriesTypeWanted != 'anime'
        ) {
            LogSonarr::error('WRONG SERIES TYPE GIVEN, should be "" or standard or daily or anime');
            return '';
        }
        return $seriesTypeWanted;
    }

    public function retrieveMonitoreOptions($monitoreType)
    {
        if ($monitoreType == '') {
            return 'all';
        }
        if (
            $monitoreType != 'all' &&
            $monitoreType != 'future' &&
            $monitoreType != 'missing' &&
            $monitoreType != 'existing' &&
            $monitoreType != 'pilot' &&
            $monitoreType != 'firstSeason' &&
            $monitoreType != 'none' &&
            $monitoreType != 'latestSeason'
        ) {
            LogSonarr::error('WRONG SERIES TYPE GIVEN, should be "" or standard or daily or anime');
            return 'all';
        }
        return $monitoreType;
    }
}

class AddOptions
{
    public $serie;
    public $profile;
    public $path;
    public $tags;
    public $seriesType;
    public $monitoringType;

    function __construct($dataJSON)
    {
        $data = json_decode($dataJSON, true);
        if ($data != '' && $data != null) {
            if (isset($data['serie']))
                $this->serie = $data['serie'];

            if (isset($data['movie']))
                $this->serie = $data['movie'];

            if (isset($data['profile']))
                $this->profile = $data['profile'];

            if (isset($data['path']))
                $this->path = $data['path'];

            if (isset($data['tags']))
                $this->tags = $data['tags'];

            if (isset($data['seriesType']))
                $this->seriesType = $data['seriesType'];

            if (isset($data['monitoringType']))
                $this->monitoringType = $data['monitoringType'];
        }
    }
}

class SeriesSearch
{
    public $series;

    function __construct($dataJSON)
    {
        $data = json_decode($dataJSON, true);
        if ($data != '' && $data != null) {
            $array_series = array();
            foreach ($data as $value) {
                $serie = new SerieSearch($value);
                array_push($array_series, $serie);
            }
            $this->series = $array_series;
        }
    }
}
class SerieSearch
{
    public $title;
    public $seasonCount;
    public $year;
    public $tvdbId;
    public $titleSlug;
    public $images;
    public $seasons;

    function __construct($data)
    {
        if (isset($data['title']))
            $this->title = $data['title'];

        if (isset($data['seasonCount']))
            $this->seasonCount = $data['seasonCount'];

        if (isset($data['year']))
            $this->year = $data['year'];

        if (isset($data['tvdbId']))
            $this->tvdbId = $data['tvdbId'];

        if (isset($data['titleSlug']))
            $this->titleSlug = $data['titleSlug'];

        if (isset($data['images']))
            $this->images = $data['images'];

        if (isset($data['seasons']))
            $this->seasons = $data['seasons'];
    }
}


class Profiles
{
    public $profiles;

    function __construct($dataJSON)
    {
        $data = json_decode($dataJSON, true);
        if ($data != '' && $data != null) {
            $array_profiles = array();
            foreach ($data as $value) {
                $profile = new Profile($value);
                array_push($array_profiles, $profile);
            }
            $this->profiles = $array_profiles;
        }
    }
}

class Profile
{
    public $name;
    public $id;

    function __construct($data)
    {
        if (isset($data['name']))
            $this->name = $data['name'];

        if (isset($data['id']))
            $this->id = $data['id'];
    }
}

class Paths
{
    public $paths;

    function __construct($dataJSON)
    {
        $data = json_decode($dataJSON, true);
        if ($data != '' && $data != null) {
            $array_paths = array();
            foreach ($data as $value) {
                $path = new Path($value);
                array_push($array_paths, $path);
            }
            $this->paths = $array_paths;
        }
    }
}

class Path
{
    public $path;

    function __construct($data)
    {
        if (isset($data['path']))
            $this->path = $data['path'];
    }
}

class Tags
{
    public $tags;

    function __construct($dataJSON)
    {
        $data = json_decode($dataJSON, true);
        if ($data != '' && $data != null) {
            $array_tags = array();
            foreach ($data as $value) {
                $tag = new Tag($value);
                array_push($array_tags, $tag);
            }
            $this->tags = $array_tags;
        }
    }
}

class Tag
{
    public $label;
    public $id;

    function __construct($data)
    {
        if (isset($data['label']))
            $this->label = $data['label'];

        if (isset($data['id']))
            $this->id = $data['id'];
    }
}
