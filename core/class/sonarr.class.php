<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';
require_once __DIR__  . '/sonarrApiWrapper.class.php';
require_once __DIR__  . '/radarrApiWrapper.class.php';
require_once __DIR__ . '/../../vendor/mips/jeedom-tools/src/MipsTrait.php';

class sonarr extends eqLogic
{
   use MipsTrait;

   public function getImage()
   {
      $application = $this->getConfiguration('application', '');
      if ($application == 'sonarr') {
         return 'plugins/sonarr/plugin_info/sonarr.png';
      } else if ($application == 'radarr') {
         return 'plugins/sonarr/plugin_info/radarr.png';
      }
   }

   public static function getApplications()
   {
      $return = array(
         'sonarr' => 'Sonarr',
         'radarr' => 'Radarr'
      );
      return $return;
   }

   private function removeUnusedCommands($commandsDef)
   {
      foreach ($this->getCmd() as $cmd) {
         if (!in_array($cmd->getLogicalId(), array_column($commandsDef, 'logicalId'))) {
            log::add(__CLASS__, 'debug', "Removing {$cmd->getLogicalId()}");
            $cmd->remove();
         }
      }
   }

   public static function cron()
   {
      foreach (self::byType('sonarr', true) as $sonarr) {
         if ($sonarr->getIsEnable() != 1) continue;
         $autorefresh = $sonarr->getConfiguration('autorefresh');
         if ($autorefresh == '')  continue;
         try {
            $cron = new Cron\CronExpression($autorefresh, new Cron\FieldFactory);
            if ($cron->isDue()) {
               $sonarr->refresh();
            }
         } catch (Exception $e) {
            log::add('sonarr', 'error', __('Expression cron non valide pour ', __FILE__) . $sonarr->getHumanName() . ' : ' . $autorefresh);
         }
      }
   }

   public function postSave()
   {
      $commands = self::getCommandsFileContent(__DIR__ . '/../config/commands.json');

      $application = $this->getConfiguration('application', '');
      if ($application == '') {
         $this->removeUnusedCommands(array());
      } else {
         $this->removeUnusedCommands($commands[$application]);
         $this->createCommandsFromConfig($commands[$application]);
      }
   }

   public function refresh()
   {
      // Refresh datas
      $application = $this->getConfiguration('application', '');
      if ($application == '') {
         log::add('sonarr', 'info', 'impossible to refresh no application set. You have to set Sonarr or Radarr');
      } else {
         if ($application == 'sonarr') {
            $this->refreshSonarr($application);
         } else if ($application == 'radarr') {
            $this->refreshRadarr($application);
         }
      }
   }
   private function refreshSonarr($application)
   {
      log::add('sonarr', 'info', 'start REFRESH SONARR');
      $apiKey = $this->getConfiguration('apiKey');
      $url = $this->getConfiguration('sonarrUrl');
      $sonarrApiWrapper = new sonarrApiWrapper($url, $apiKey);
      $separator = $this->getSeparator();
      log::add('sonarr', 'info', 'selected separator: ' . $separator);
      $formattor = $this->getConfiguration('formattorEpisode');
      log::add('sonarr', 'info', 'selected formattor: ' . $formattor);
      log::add('sonarr', 'info', 'getting futures episodes, will look for selected rule');
      $futurEpisodesRules = $this->getConfigurationFor($this, "dayFutureEpisodes", "maxFutureEpisodes");
      $futurEpisodeList = $sonarrApiWrapper->getFutureEpisodesFormattedList($separator, $futurEpisodesRules, $formattor);
      if ($futurEpisodeList == "") {
         log::add('sonarr', 'info', 'no future episodes');
      }
      $this->checkAndUpdateCmd('day_episodes', $futurEpisodeList);
      log::add('sonarr', 'info', 'getting missings episodes, will look for selected rule');
      $missingEpisodesRules = $this->getConfigurationFor($this, "dayMissingEpisodes", "maxMissingEpisodes");
      $missingEpisodesList = $sonarrApiWrapper->getMissingEpisodesFormattedList($missingEpisodesRules, $separator, $formattor);
      if ($missingEpisodesList == "") {
         log::add('sonarr', 'info', 'no missing episodes');
      }
      $this->checkAndUpdateCmd('day_missing_episodes', $missingEpisodesList);
      log::add('sonarr', 'info', 'getting last downloaded episodes, will look for specific rules');
      $downloadedEpisodesRules = $this->getConfigurationFor($this, "dayDownloadedEpisodes", "maxDownloadedEpisodes");
      $dowloadedEpisodesList = $sonarrApiWrapper->getDownladedEpisodesFormattedList($downloadedEpisodesRules, $separator, $formattor);
      if ($dowloadedEpisodesList == "") {
         log::add('sonarr', 'info', 'no downloaded episodes');
      }
      $this->checkAndUpdateCmd('day_ddl_episodes', $dowloadedEpisodesList);
      log::add('sonarr', 'info', 'notify for last downloaded episodes');
      $last_refresh_date = $this->getCmd(null, 'last_episode')->getValueDate();
      $sonarrApiWrapper->notifyEpisode($application, $last_refresh_date, $this, $formattor);
      log::add('sonarr', 'info', 'getting the monitored series');
      $liste_monitored_series = $sonarrApiWrapper->getMonitoredSeries($separator);
      if ($liste_monitored_series == "") {
         log::add('sonarr', 'info', 'no monitored series');
      } else {
         $this->checkAndUpdateCmd('monitoredSeries', $liste_monitored_series);
      }
      log::add('sonarr', 'info', 'stop REFRESH SONARR');
   }

   private function refreshRadarr($application)
   {
      log::add('sonarr', 'info', 'start REFRESH RADARR');
      $apiKey = $this->getConfiguration('apiKey');
      $url = $this->getConfiguration('radarrUrl');
      $radarrApiWrapper = new radarrApiWrapper($url, $apiKey);
      $separator = $this->getSeparator();
      log::add('sonarr', 'info', 'selected separator: ' . $separator);
      log::add('sonarr', 'info', 'getting futures movies, will look for selected rule');
      $futurMoviesRules = $this->getConfigurationFor($this, "dayFutureMovies", "maxFutureMovies");
      $futurMovieList = $radarrApiWrapper->getFutureMoviesFormattedList($separator, $futurMoviesRules);
      if ($futurMovieList == "") {
         log::add('sonarr', 'info', 'no future movies');
      }
      log::add('sonarr', 'info', 'futur movie list: ' . $futurMovieList);
      $this->checkAndUpdateCmd('day_movies', $futurMovieList);
      log::add('sonarr', 'info', 'getting missings movies');
      $missingMoviesList = $radarrApiWrapper->getMissingMoviesFormattedList($separator);
      if ($missingMoviesList == "") {
         log::add('sonarr', 'info', 'no missing movies');
      }
      $this->checkAndUpdateCmd('day_missing_movies', $missingMoviesList);
      log::add('sonarr', 'info', 'getting last downloaded movies, will look for selected rules');
      $downloadMoviesRules = $this->getConfigurationFor($this, "dayDownloadedMovies", "maxDownloadedMovies");
      $downloadMoviesList = $radarrApiWrapper->getDownladedMoviesFormattedList($downloadMoviesRules, $separator);
      if ($downloadMoviesList == "") {
         log::add('sonarr', 'info', 'no downloaded movies');
      }
      $this->checkAndUpdateCmd('day_ddl_movies', $downloadMoviesList);
      log::add('sonarr', 'info', 'notify for last downloaded movies');
      $last_refresh_date = $this->getCmd(null, 'last_episode')->getValueDate();
      $radarrApiWrapper->notifyMovie($application, $last_refresh_date, $this);
      log::add('sonarr', 'info', 'stop REFRESH RADARR');
   }

   public function getSeparator()
   {
      $separator = $this->getConfiguration('separator');
      if ($separator != NULL) {
         return $separator;
      } else {
         return ", ";
      }
   }
   public function getConfigurationFor($context, $numberDaysConfig, $numberMaxConfig)
   {
      $numberDays = $context->getConfiguration($numberDaysConfig);
      if ($numberDays == NULL || !is_numeric($numberDays)) {
         $numberDays = 1;
      }
      log::add('sonarr', 'info', 'Configuration for ' . $numberDaysConfig . ' is set to ' . $numberDays);
      $numberMax = $context->getConfiguration($numberMaxConfig);
      if ($numberMax == NULL && !is_numeric($numberMax)) {
         $numberMax = NULL;
         log::add('sonarr', 'info', 'Configuration for ' . $numberMaxConfig . ' not set, will use only day rule');
      } else {
         log::add('sonarr', 'info', 'Configuration for ' . $numberMaxConfig . ' is set to ' . $numberMax);
      }
      $rules = array(
         'numberDays' => $numberDays,
         'numberMax' => $numberMax,
      );
      return $rules;
   }
   private function generateHtmlForDatas($datas, $_version, $application, $needInfosSup)
   {
      $html = '';
      foreach ($datas as $data) {
         $replace_ep = $this->getGenericReplace($data, $application, $needInfosSup);
         // generate HTML
         $html_obj = template_replace($replace_ep, getTemplate('core', $_version, 'sonarr_cmd', 'sonarr'));
         $html = $html . $html_obj;
      }
      return $html;
   }
   private function generateHtmlForDatasCondensed($datas, $_version, $application, $needInfosSup)
   {
      $html = '';
      foreach ($datas as $data) {
         $replace_ep = $this->getGenericReplace($data, $application, $needInfosSup);
         // generate HTML
         $html = $html . "<div class=\"div_horizontal\">";
         $html_obj = template_replace($replace_ep, getTemplate('core', $_version, 'sonarr_cmd_condensed', 'sonarr'));
         $html = $html . $html_obj;
         if ($datas["downloaded"] == true) {
            $html = $html . "<img class=\"ddl_img_icon\" src=\"plugins/sonarr/core/template/dashboard/imgs/downloaded_icon.svg\" alt=\"downloaded_icon\"/>";
            $html = $html . "<div class=\"info_data\">" . $replace_ep["#info_supp#"] . "</div>";
         }
         $html = $html . "</div>";
      }
      return $html;
   }
   private function getGenericReplace($data, $application, $needInfosSup)
   {
      $replace_ep = [];
      if ($application == 'sonarr') {
         $replace_ep['#img_poster#'] = 'plugins/sonarr/core/template/dashboard/imgs/sonarr_' . $data['seriesId'] . '.jpg';
      } else {
         $replace_ep['#img_poster#'] = 'plugins/sonarr/core/template/dashboard/imgs/radarr_' . $data['seriesId'] . '.jpg';
      }
      $replace_ep['#title#'] = $data['title'];
      $replace_ep['#date#'] = $data['date'];
      if ($needInfosSup == true) {
         $replace_ep['#info_supp#'] = $data['quality'] . " " . $data['size'];
      } else {
         $replace_ep['#info_supp#'] = '';
      }
      return $replace_ep;
   }
   public function toHtml($_version = 'dashboard')
   {
      $replace = $this->preToHtml($_version);
      if (!is_array($replace)) {
         return $replace;
      }
      $version = jeedom::versionAlias($_version);

      $application = $this->getConfiguration('application', '');

      $apiKey = $this->getConfiguration('apiKey');
      $formattor = $this->getConfiguration('formattorEpisode');

      $html = '';
      foreach ($this->getCmd(null, null, true) as $cmd) {
         $condensed = $this->getConfiguration('condensedWidget');
         $url = $this->getConfiguration('sonarrUrl');
         $sonarrApiWrapper = new sonarrApiWrapper($url, $apiKey);
         if ($application == 'sonarr') {
            if ($cmd->getLogicalId() == "day_episodes") {
               $html = $html . "<legend style=\"color : white;margin-bottom:2px;\"><b>";
               $html = $html . __("Episodes à venir", __FILE__) . "</b></legend><div class=\"div_vertical\">";
               $futurEpisodesRules = $this->getConfigurationFor($this, "dayFutureEpisodes", "maxFutureEpisodes");
               $futurEpisodesRules["numberMax"] = 3;
               $futurEpisodeList = $sonarrApiWrapper->getFutureEpisodesArray($futurEpisodesRules, $formattor);
               if ($condensed == 0) {
                  $html = $html . $this->generateHtmlForDatas($futurEpisodeList, $_version, $application, false);
               } else {
                  $html = $html . $this->generateHtmlForDatasCondensed($futurEpisodeList, $_version, $application, true);
               }
               $html = $html . "</div>";
            }
            if ($condensed == 0) {
               if ($cmd->getLogicalId() == "day_ddl_episodes") {
                  $html = $html . "<legend style=\"color : white;margin-bottom:2px;\"><b>";
                  $html = $html . __("Episodes téléchargés", __FILE__) . "</b></legend><div class=\"div_vertical\">";
                  $downloadedEpisodesRules = $this->getConfigurationFor($this, "dayDownloadedEpisodes", "maxDownloadedEpisodes");
                  $downloadedEpisodesRules["numberMax"] = 3;
                  $ddlEpisodesList = $sonarrApiWrapper->getDownloadedEpisodesArray($downloadedEpisodesRules, $formattor);
                  $html = $html . $this->generateHtmlForDatas($ddlEpisodesList, $_version, $application, true);
                  $html = $html . "</div>";
               }
               if ($cmd->getLogicalId() == "day_missing_episodes") {
                  $html = $html . "<legend style=\"color : white;margin-bottom:2px;\"><b>";
                  $html = $html . __("Episodes manquants", __FILE__) . "</b></legend><div class=\"div_vertical\">";
                  $missingEpisodesRules = $this->getConfigurationFor($this, "dayMissingEpisodes", "maxMissingEpisodes");
                  $missingEpisodesRules["numberMax"] = 3;
                  $missingEpisodesList = $sonarrApiWrapper->getMissingEpisodesArray($missingEpisodesRules, $formattor);
                  $html = $html . $this->generateHtmlForDatas($missingEpisodesList, $_version, $application, false);
                  $html = $html . "</div>";
               }
            }
         } else {
            $url = $this->getConfiguration('radarrUrl');
            $radarrApiWrapper = new radarrApiWrapper($url, $apiKey);
            if ($cmd->getLogicalId() == "day_movies") {
               $html = $html . "<legend style=\"color : white;margin-bottom:2px;\"><b>";
               $html = $html . __("Films à venir", __FILE__) . "</b></legend><div class=\"div_vertical\">";
               $futurMoviesRules = $this->getConfigurationFor($this, "dayFutureMovies", "maxFutureMovies");
               $futurMoviesRules["numberMax"] = 3;
               $futurMovieList = $radarrApiWrapper->getFutureMoviesArray($futurMoviesRules);
               if ($condensed == 0) {
                  $html = $html . $this->generateHtmlForDatas($futurMovieList, $_version, $application, false);
               } else {
                  $html = $html . $this->generateHtmlForDatasCondensed($futurMovieList, $_version, $application, true);
               }
               $html = $html . "</div>";
            }
            if ($condensed == 0) {
               if ($cmd->getLogicalId() == "day_ddl_movies") {
                  $html = $html . "<legend style=\"color : white;margin-bottom:2px;\"><b>";
                  $html = $html . __("Films téléchargés", __FILE__) . "</b></legend><div class=\"div_vertical\">";
                  $downloadMoviesRules = $this->getConfigurationFor($this, "dayDownloadedMovies", "maxDownloadedMovies");
                  $downloadMoviesRules["numberMax"] = 3;
                  $ddlMovieList = $radarrApiWrapper->getDownloadedMoviesArray($downloadMoviesRules);
                  $html = $html . $this->generateHtmlForDatas($ddlMovieList, $_version, $application, true);
                  $html = $html . "</div>";
               }
               if ($cmd->getLogicalId() == "day_missing_movies") {
                  $html = $html . "<legend style=\"color : white;margin-bottom:2px;\"><b>";
                  $html = $html . __("Films manquants", __FILE__) . "</b></legend><div class=\"div_vertical\">";
                  $missingMovieRules["numberMax"] = 3;
                  $missingMovieList = $radarrApiWrapper->getMissingMoviesArray($missingMovieRules);
                  $html = $html . $this->generateHtmlForDatas($missingMovieList, $_version, $application, false);
                  $html = $html . "</div>";
               }
            }
         }
      }
      $replace["#cmds#"] = $html;
      return template_replace($replace, getTemplate('core', $version, 'sonarr_template', 'sonarr'));
   }
}

class sonarrCmd extends cmd
{
   // Exécution d'une commande  
   public function execute($_options = array())
   {
      $eqlogic = $this->getEqLogic();
      switch ($this->getLogicalId()) {
         case 'refresh':
            $eqlogic->refresh();
            break;
      }
   }
}
