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
require_once __DIR__  . '/Utils/LogSonarr.php';
require_once __DIR__  . '/Utils/SonarrRadarrUtils.php';



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
            LogSonarr::error(__('Expression cron non valide pour ', __FILE__) . $sonarr->getHumanName() . ' : ' . $autorefresh);
         }
      }
   }

   public function postSave()
   {
      $this->createCmdIfNeeded();
   }

   public function createCmdIfNeeded()
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
         LogSonarr::info('impossible to refresh no application set. You have to set Sonarr or Radarr');
      } else {
         if ($application == 'sonarr') {
            $apiKey = $this->getConfiguration('apiKey');
            $url = $this->getConfiguration('sonarrUrl');
            $sonarrApiWrapper = new sonarrApiWrapper($url, $apiKey);
            $sonarrApiWrapper->refreshSonarr($this);
         } else if ($application == 'radarr') {
            $apiKey = $this->getConfiguration('apiKey');
            $url = $this->getConfiguration('radarrUrl');
            $radarrApiWrapper = new radarrApiWrapper($url, $apiKey);
            $radarrApiWrapper->refreshRadarr($this);
         }
      }
      // Refresh View
      $this->refreshWidget();
   }

   public function search($_options)
   {
      // Depends of application
      $application = SonarrRadarrUtils::verifyConfiguration($this, 'application');
      if ($application == null) {
         return;
      }
      if ($application == 'sonarr') {
         $apiKey = SonarrRadarrUtils::verifyConfiguration($this, 'apiKey');
         $url = SonarrRadarrUtils::verifyConfiguration($this, 'sonarrUrl');
         if ($apiKey == null || $url == null) {
            return;
         }
         $sonarrApiWrapper = new sonarrApiWrapper($url, $apiKey);
         $sonarrApiWrapper->searchForSerie($this, $_options['message']);
      } else if ($application == 'radarr') {
         $apiKey = SonarrRadarrUtils::verifyConfiguration($this, 'apiKey');
         $url = SonarrRadarrUtils::verifyConfiguration($this, 'radarrUrl');
         if ($apiKey == null || $url == null) {
            return;
         }
         $radarrApiWrapper = new radarrApiWrapper($url, $apiKey);
         $radarrApiWrapper->searchForMovie($this, $_options['message']);
      }
   }

   public function addSerieMovie($_options)
   {
      // Depends of application
      $application = SonarrRadarrUtils::verifyConfiguration($this, 'application');
      if ($application == null) {
         return;
      }
      if ($application == 'sonarr') {
         $apiKey = SonarrRadarrUtils::verifyConfiguration($this, 'apiKey');
         $url = SonarrRadarrUtils::verifyConfiguration($this, 'sonarrUrl');
         if ($apiKey == null || $url == null) {
            return;
         }
         $sonarrApiWrapper = new sonarrApiWrapper($url, $apiKey);
         $sonarrApiWrapper->addSerie($this, $_options);
      } else if ($application == 'radarr') {
         $apiKey = SonarrRadarrUtils::verifyConfiguration($this, 'apiKey');
         $url = SonarrRadarrUtils::verifyConfiguration($this, 'radarrUrl');
         if ($apiKey == null || $url == null) {
            return;
         }
         $radarrApiWrapper = new radarrApiWrapper($url, $apiKey);
         $radarrApiWrapper->addMovie($this, $_options);
      }
   }
   public function getProfiles()
   {
      // Depends of application
      $application = SonarrRadarrUtils::verifyConfiguration($this, 'application');
      if ($application == null) {
         return;
      }
      if ($application == 'sonarr') {
         $apiKey = SonarrRadarrUtils::verifyConfiguration($this, 'apiKey');
         $url = SonarrRadarrUtils::verifyConfiguration($this, 'sonarrUrl');
         if ($apiKey == null || $url == null) {
            return;
         }
         $sonarrApiWrapper = new sonarrApiWrapper($url, $apiKey);
         $sonarrApiWrapper->getProfiles($this);
      } else if ($application == 'radarr') {
         $apiKey = SonarrRadarrUtils::verifyConfiguration($this, 'apiKey');
         $url = SonarrRadarrUtils::verifyConfiguration($this, 'radarrUrl');
         if ($apiKey == null || $url == null) {
            return;
         }
         $radarrApiWrapper = new radarrApiWrapper($url, $apiKey);
         $radarrApiWrapper->getProfiles($this);
      }
   }
   public function getPaths()
   {
      // Depends of application
      $application = SonarrRadarrUtils::verifyConfiguration($this, 'application');
      if ($application == null) {
         return;
      }
      if ($application == 'sonarr') {
         $apiKey = SonarrRadarrUtils::verifyConfiguration($this, 'apiKey');
         $url = SonarrRadarrUtils::verifyConfiguration($this, 'sonarrUrl');
         if ($apiKey == null || $url == null) {
            return;
         }
         $sonarrApiWrapper = new sonarrApiWrapper($url, $apiKey);
         $sonarrApiWrapper->getPaths($this);
      } else if ($application == 'radarr') {
         $apiKey = SonarrRadarrUtils::verifyConfiguration($this, 'apiKey');
         $url = SonarrRadarrUtils::verifyConfiguration($this, 'radarrUrl');
         if ($apiKey == null || $url == null) {
            return;
         }
         $radarrApiWrapper = new radarrApiWrapper($url, $apiKey);
         $radarrApiWrapper->getPaths($this);
      }
   }
   public function getSonarrRadarrTags()
   {
      // Depends of application
      $application = SonarrRadarrUtils::verifyConfiguration($this, 'application');
      if ($application == null) {
         return;
      }
      if ($application == 'sonarr') {
         $apiKey = SonarrRadarrUtils::verifyConfiguration($this, 'apiKey');
         $url = SonarrRadarrUtils::verifyConfiguration($this, 'sonarrUrl');
         if ($apiKey == null || $url == null) {
            return;
         }
         $sonarrApiWrapper = new sonarrApiWrapper($url, $apiKey);
         $sonarrApiWrapper->getSonarrTags($this);
      } else if ($application == 'radarr') {
         $apiKey = SonarrRadarrUtils::verifyConfiguration($this, 'apiKey');
         $url = SonarrRadarrUtils::verifyConfiguration($this, 'radarrUrl');
         if ($apiKey == null || $url == null) {
            return;
         }
         $radarrApiWrapper = new radarrApiWrapper($url, $apiKey);
         $radarrApiWrapper->getRadarrTags($this);
      }
   }

   public function searchMissing()
   {
      // Depends of application
      $application = SonarrRadarrUtils::verifyConfiguration($this, 'application');
      if ($application == null) {
         return;
      }
      if ($application == 'sonarr') {
         $apiKey = SonarrRadarrUtils::verifyConfiguration($this, 'apiKey');
         $url = SonarrRadarrUtils::verifyConfiguration($this, 'sonarrUrl');
         if ($apiKey == null || $url == null) {
            return;
         }
         $sonarrApiWrapper = new sonarrApiWrapper($url, $apiKey);
         $sonarrApiWrapper->searchMissing($this);
      } else if ($application == 'radarr') {
         $apiKey = SonarrRadarrUtils::verifyConfiguration($this, 'apiKey');
         $url = SonarrRadarrUtils::verifyConfiguration($this, 'radarrUrl');
         if ($apiKey == null || $url == null) {
            return;
         }
         $radarrApiWrapper = new radarrApiWrapper($url, $apiKey);
         $radarrApiWrapper->searchMissing($this);
      }
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
      LogSonarr::info('Configuration for ' . $numberDaysConfig . ' is set to ' . $numberDays);
      $numberMax = $context->getConfiguration($numberMaxConfig);
      if ($numberMax == NULL && !is_numeric($numberMax)) {
         $numberMax = NULL;
         LogSonarr::info('Configuration for ' . $numberMaxConfig . ' not set, will use only day rule');
      } else {
         LogSonarr::info('Configuration for ' . $numberMaxConfig . ' is set to ' . $numberMax);
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
         if ($data["downloaded"] == true) {
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
      LogSonarr::info("REFRESH WIDGET FOR: " . $application);
      $html = '';
      foreach ($this->getCmd(null, null, true) as $cmd) {
         $condensed = $this->getConfiguration('condensedWidget');
         if ($application == 'sonarr') {
            $url = $this->getConfiguration('sonarrUrl');
            if ($cmd->getLogicalId() == "day_episodes") {
               $futurEpRawCmd = $this->getCmd(null, 'day_episodes_raw');
               if ($futurEpRawCmd != null) {
                  $html = $html . $this->addCmdName($cmd, $_version, "Episodes à venir");
                  $futurEpisodeList = json_decode($futurEpRawCmd->execCmd(), true);
                  if (is_array($futurEpisodeList)) {
                     $futurEpisodeList = sonarrUtils::applyMaxToArray($futurEpisodeList, 3);
                     if ($condensed == 0) {
                        $html = $html . $this->generateHtmlForDatas($futurEpisodeList, $_version, $application, false);
                     } else {
                        $html = $html . $this->generateHtmlForDatasCondensed($futurEpisodeList, $_version, $application, true);
                     }
                  }
               } else {
                  LogSonarr::error('missing cmd day_episodes_raw');
               }
               $html = $html . "</div>";
            }
            if ($condensed == 0) {
               if ($cmd->getLogicalId() == "day_ddl_episodes") {
                  $ddlEpRawCmd = $this->getCmd(null, 'day_ddl_episodes_raw');
                  if ($ddlEpRawCmd != null) {
                     $html = $html . $this->addCmdName($cmd, $_version, "Episodes téléchargés");
                     $ddlEpisodesList = json_decode($ddlEpRawCmd->execCmd(), true);
                     if (is_array($ddlEpisodesList)) {
                        $ddlEpisodesList = sonarrUtils::applyMaxToArray($ddlEpisodesList, 3);
                        $html = $html . $this->generateHtmlForDatas($ddlEpisodesList, $_version, $application, true);
                     }
                  } else {
                     LogSonarr::error('missing cmd day_ddl_episodes_raw');
                  }
                  $html = $html . "</div>";
               }
               if ($cmd->getLogicalId() == "day_missing_episodes") {
                  $missingEpRawCmd = $this->getCmd(null, 'day_missing_episodes_raw');
                  if ($missingEpRawCmd != null) {
                     $html = $html . $this->addCmdName($cmd, $_version, "Episodes manquants");
                     $missingEpisodesList = json_decode($missingEpRawCmd->execCmd(), true);
                     if (is_array($missingEpisodesList)) {
                        $missingEpisodesList = sonarrUtils::applyMaxToArray($missingEpisodesList, 3);
                        $html = $html . $this->generateHtmlForDatas($missingEpisodesList, $_version, $application, false);
                     }
                  } else {
                     LogSonarr::error('missing cmd day_missing_episodes_raw');
                  }
                  $html = $html . "</div>";
               }
            }
         } else {
            if ($cmd->getLogicalId() == "day_movies") {
               $futurMovRawCmd = $this->getCmd(null, 'day_movies_raw');
               if ($futurMovRawCmd != null) {
                  $html = $html . $this->addCmdName($cmd, $_version, "Films à venir");
                  $futurMoviesList = json_decode($futurMovRawCmd->execCmd(), true);
                  if (is_array($futurMoviesList)) {
                     $futurMoviesList = sonarrUtils::applyMaxToArray($futurMoviesList, 3);
                     if ($condensed == 0) {
                        $html = $html . $this->generateHtmlForDatas($futurMoviesList, $_version, $application, false);
                     } else {
                        $html = $html . $this->generateHtmlForDatasCondensed($futurMoviesList, $_version, $application, true);
                     }
                  }
               } else {
                  LogSonarr::error('missing cmd day_movies_raw');
               }
               $html = $html . "</div>";
            }
            if ($condensed == 0) {
               if ($cmd->getLogicalId() == "day_ddl_movies") {
                  $ddlMovRawCmd = $this->getCmd(null, 'day_ddl_movies_raw');
                  if ($ddlMovRawCmd != null) {
                     $html = $html . $this->addCmdName($cmd, $_version, "Films téléchargés");
                     $ddlMoviesList = json_decode($ddlMovRawCmd->execCmd(), true);
                     if (is_array($ddlMoviesList)) {
                        $ddlMoviesList = sonarrUtils::applyMaxToArray($ddlMoviesList, 3);
                        $html = $html . $this->generateHtmlForDatas($ddlMoviesList, $_version, $application, true);
                     }
                  } else {
                     LogSonarr::error('missing cmd day_ddl_movies_raw');
                  }
                  $html = $html . "</div>";
               }
               if ($cmd->getLogicalId() == "day_missing_movies") {
                  $missingMovRawCmd = $this->getCmd(null, 'day_missing_movies_raw');
                  if ($missingMovRawCmd != null) {
                     $html = $html . $this->addCmdName($cmd, $_version, "Films manquants");
                     $missingMoviesList = json_decode($missingMovRawCmd->execCmd(), true);
                     if (is_array($missingMoviesList)) {
                        $missingMoviesList = sonarrUtils::applyMaxToArray($missingMoviesList, 3);
                        $html = $html . $this->generateHtmlForDatas($missingMoviesList, $_version, $application, false);
                     }
                  } else {
                     LogSonarr::error('missing cmd day_missing_movies_raw');
                  }
                  $html = $html . "</div>";
               }
            }
         }
      }
      $replace["#cmds#"] = $html;
      if ($application == 'sonarr') {
         $url = $this->getConfiguration('sonarrUrl');
         $replace["#server_url#"] = $url;
      } else if ($application == 'radarr') {
         $url = $this->getConfiguration('radarrUrl');
         $replace["#server_url#"] = $url;
      }
      return template_replace($replace, getTemplate('core', $version, 'sonarr_template', 'sonarr'));
   }
   private function addCmdName($cmd, $_version, $label)
   {
      $html = '';
      if ($cmd->getDisplay('showNameOn' . $_version, 1) == 1 || $cmd->getDisplay('showIconAndName' . $_version, 0) == 1) {
         $html = $html . "<legend style=\"color : white;margin-bottom:2px;\"><b>";
         $html = $html . __($label, __FILE__) . "</b></legend>";
      } else {
         $html = $html . "<br>";
      }

      $html = $html . "<div class=\"div_vertical\">";
      return $html;
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
         case 'search_action':
            $eqlogic->search($_options);
            break;
         case 'add_serie':
            $eqlogic->addSerieMovie($_options);
            break;
         case 'get_profiles':
            $eqlogic->getProfiles();
            break;
         case 'get_path':
            $eqlogic->getPaths();
            break;
         case 'get_tags':
            $eqlogic->getSonarrRadarrTags();
            break;
         case 'search_missing':
            $eqlogic->searchMissing();
            break;
      }
   }
}
