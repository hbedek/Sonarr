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
require_once __DIR__ . '/../../vendor/autoload.php'; //Tonio aded 25/12/2025 après migration en Jeedom 4.5.1 qui supprime Guzzle/http/Client
require_once __DIR__  . '/sonarrApiWrapper.class.php';
require_once __DIR__  . '/radarrApiWrapper.class.php';
require_once __DIR__  . '/overseerrApiWrapper.class.php'; // Tonio addded
require_once __DIR__ . '/../../vendor/mips/jeedom-tools/src/MipsTrait.php';
require_once __DIR__  . '/Utils/LogSonarr.php';
require_once __DIR__  . '/Utils/SonarrRadarrUtils.php';



class sonarr extends eqLogic
{
   use MipsTrait;

   //Tonio Ajout d'une fonction pour nettoyer le répertoire d'image après le refresh
   public static function cleanUnusedImages($context)
   {
      LogSonarr::debug("Début du nettoyage");
      $imgDir = __DIR__ . '/../template/dashboard/imgs/';
      if (!is_dir($imgDir)) {
         return;
      }
      LogSonarr::debug("Nettoyage, imgDir = $imgDir");
      $application = $context->getConfiguration('application', '');
      LogSonarr::debug("Nettoyage, application = $application");
      if ($application == 'sonarr') {
         $prefixImg = 'sonarr_';
         // Liste des commandes RAW à analyser
         $cmds = [
               'day_episodes_raw',
               'day_missing_episodes_raw',
               'day_ddl_episodes_raw'
         ];
         $IDmedia = 'seriesId';
      } else if ($application == 'radarr') {
         $prefixImg = 'radarr_';
         // Liste des commandes RAW à analyser
         $cmds = [
               'day_movies_raw',
               'day_missing_movies_raw',
               'day_ddl_movies_raw'
         ];
         $IDmedia = 'seriesId';
      } else if ($application == 'overseerr') {
         $prefixImg = 'overseerr_';
         // Liste des commandes RAW à analyser
         $cmds = [
               'day_pending_raw',
               'day_approved_raw',
               'day_declined_raw'
         ];
         $IDmedia = 'mediaID';
      }
      LogSonarr::debug("Nettoyage, prefixImg = $prefixImg");
      LogSonarr::debug("Nettoyage, cmds = $cmds");
      LogSonarr::debug("Nettoyage, IDmedia = $IDmedia");
      $idsToKeep = [];

      foreach ($cmds as $cmdLogicalId) {
         LogSonarr::debug("Nettoyage, cmdLogicalId = $cmdLogicalId");
         $cmde = $context->getCmd(null, $cmdLogicalId);
         LogSonarr::debug("Nettoyage, cmdLogicalId = $cmdLogicalId; cmd obtenue");
         $value = $cmde->execCmd();
         LogSonarr::debug("Nettoyage, cmdLogicalId = $cmdLogicalId --> value = $value");
         $data = json_decode($value, true);
        LogSonarr::debug("Nettoyage, cmdLogicalId = $cmdLogicalId --> data = $data");

         foreach ($data as $movie) {
               if (!empty($movie[$IDmedia])) {
                  $idsToKeep[] = (string)$movie[$IDmedia];
               }
         }
         LogSonarr::debug("Nettoyage, cmdLogicalId = $cmdLogicalId --> idsToKeep = $idsToKeep");

      }

      $idsToKeep = array_unique($idsToKeep);
      if (empty($idsToKeep)) {
        LogSonarr::debug('Nettoyage images ignoré : aucune ID valide trouvée');
        return;
      }

      // Parcours des fichiers images
      foreach (glob($imgDir . $prefixImg.'*') as $file) {
         if (!is_file($file)) {
               continue;
         }

         // Extraction de l’ID depuis le nom du fichier
         if (preg_match('/'.preg_quote($prefixImg, '/').'(\d+)/', basename($file), $matches)) {
               $fileId = $matches[1];

               if (!in_array($fileId, $idsToKeep, true)) {
                  unlink($file);
                  LogSonarr::info("Suppression affiche ".$application." inutile : " . basename($file));
               }
         }
      }
   }

   public function getImage()
   {
      $application = $this->getConfiguration('application', '');
      if ($application == 'sonarr') {
         return 'plugins/sonarr/plugin_info/sonarr.png';
      } else if ($application == 'radarr') {
         return 'plugins/sonarr/plugin_info/radarr.png';
      } else if ($application == 'overseerr') { //Tonio
      return 'plugins/sonarr/plugin_info/overseerr.png'; //Tonio
      }
   }

   public static function getApplications()
   {
      $return = array(
         'sonarr' => 'Sonarr',
         'radarr' => 'Radarr', //Tonio uniquement la virgule à la fin
         'overseerr' => 'Overseerr' //Tonio
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
         // Start Tonio
         else if ($application == 'overseerr') {
            $apiKey = $this->getConfiguration('apiKey');
            $url = $this->getConfiguration('overseerrUrl');
            LogSonarr::debug('Tonio - apikey = '. $apiKey.' - URL = '.$url);
            $overseerrApiWrapper = new overseerrApiWrapper($url, $apiKey);
            LogSonarr::debug('Tonio - après new apiwrapper');
            $overseerrApiWrapper->refreshOverseerr($this);
            LogSonarr::debug('Tonio - après refresh');

         }
         // Fin Tonio
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
      } elseif  ($application == 'radarr') { // Tonio ajout "if  ($application == 'radarr')"
         $replace_ep['#img_poster#'] = 'plugins/sonarr/core/template/dashboard/imgs/radarr_' . $data['seriesId'] . '.jpg';
      }
      // Start Tonio
      elseif ($application == 'overseerr') {
         $replace_ep['#img_poster#'] = 'plugins/sonarr/core/template/dashboard/imgs/overseerr_' . $data['mediaID'] . '.jpg';
      }
      // Fin Tonio
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
                  $futurEpisodeList = json_decode($futurEpRawCmd->execCmd(), true);
                  if (is_array($futurEpisodeList)) {
                     if (count($futurEpisodeList) != 0) {
                        $html = $html . $this->addCmdName($cmd, $_version, "Episodes à venir");
                     $futurEpisodeList = sonarrUtils::applyMaxToArray($futurEpisodeList, 3);
                     if ($condensed == 0) {
                        $html = $html . $this->generateHtmlForDatas($futurEpisodeList, $_version, $application, false);
                     } else {
                        $html = $html . $this->generateHtmlForDatasCondensed($futurEpisodeList, $_version, $application, true);
                     }
                     } else {
                        $html = $html . $this->addCmdName($cmd, $_version, "Aucun épisode à venir");
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
                     $ddlEpisodesList = json_decode($ddlEpRawCmd->execCmd(), true);
                     if (is_array($ddlEpisodesList)) {
                        if (count($ddlEpisodesList) != 0) {
                           $html = $html . $this->addCmdName($cmd, $_version, "Episodes téléchargés");
                        $ddlEpisodesList = sonarrUtils::applyMaxToArray($ddlEpisodesList, 3);
                        $html = $html . $this->generateHtmlForDatas($ddlEpisodesList, $_version, $application, true);
                     }
                     }
                  } else {
                     LogSonarr::error('missing cmd day_ddl_episodes_raw');
                  }
                  $html = $html . "</div>";
               }
               if ($cmd->getLogicalId() == "day_missing_episodes") {
                  $missingEpRawCmd = $this->getCmd(null, 'day_missing_episodes_raw');
                  if ($missingEpRawCmd != null) {
                     $missingEpisodesList = json_decode($missingEpRawCmd->execCmd(), true);
                     if (is_array($missingEpisodesList)) {
                        if (count($missingEpisodesList) != 0) {
                           $html = $html . $this->addCmdName($cmd, $_version, "Episodes manquants");
                        $missingEpisodesList = sonarrUtils::applyMaxToArray($missingEpisodesList, 3);
                        $html = $html . $this->generateHtmlForDatas($missingEpisodesList, $_version, $application, false);
                        } else {
                           $html = $html . $this->addCmdName($cmd, $_version, "Aucun épisode manquant");
                        }
                     }
                  } else {
                     LogSonarr::error('missing cmd day_missing_episodes_raw');
                  }
                  $html = $html . "</div>";
               }
            }
         } elseif ($application == 'radarr') { //Tonio added "if ($application == 'radarr') "
            if ($cmd->getLogicalId() == "day_movies") {
               $futurMovRawCmd = $this->getCmd(null, 'day_movies_raw');
               if ($futurMovRawCmd != null) {
                  $futurMoviesList = json_decode($futurMovRawCmd->execCmd(), true);
                  if (is_array($futurMoviesList)) {
                     if (count($futurMoviesList) != 0) {
                        $html = $html . $this->addCmdName($cmd, $_version, "Films à venir");
                     $futurMoviesList = sonarrUtils::applyMaxToArray($futurMoviesList, 3);
                     if ($condensed == 0) {
                        $html = $html . $this->generateHtmlForDatas($futurMoviesList, $_version, $application, false);
                     } else {
                        $html = $html . $this->generateHtmlForDatasCondensed($futurMoviesList, $_version, $application, true);
                     }
                     } else {
                        $html = $html . $this->addCmdName($cmd, $_version, "Aucun film à venir");
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
                     $ddlMoviesList = json_decode($ddlMovRawCmd->execCmd(), true);
                     if (is_array($ddlMoviesList)) {
                        if (count($ddlMoviesList) != 0) {
                           $html = $html . $this->addCmdName($cmd, $_version, "Films téléchargés");
                        $ddlMoviesList = sonarrUtils::applyMaxToArray($ddlMoviesList, 3);
                        $html = $html . $this->generateHtmlForDatas($ddlMoviesList, $_version, $application, true);
                     }
                     }
                  } else {
                     LogSonarr::error('missing cmd day_ddl_movies_raw');
                  }
                  $html = $html . "</div>";
               }
               if ($cmd->getLogicalId() == "day_missing_movies") {
                  $missingMovRawCmd = $this->getCmd(null, 'day_missing_movies_raw');
                  if ($missingMovRawCmd != null) {
                     $missingMoviesList = json_decode($missingMovRawCmd->execCmd(), true);
                     if (is_array($missingMoviesList)) {
                        if (count($missingMoviesList) != 0) {
                           $html = $html . $this->addCmdName($cmd, $_version, "Films manquants");
                        $missingMoviesList = sonarrUtils::applyMaxToArray($missingMoviesList, 3);
                        $html = $html . $this->generateHtmlForDatas($missingMoviesList, $_version, $application, false);
                        } else {
                           $html = $html . $this->addCmdName($cmd, $_version, "Aucun film manquant");
                        }
                     }
                  } else {
                     LogSonarr::error('missing cmd day_missing_movies_raw');
                  }
                  $html = $html . "</div>";
               }
            }
         }
         // Start Tonio 
         elseif ($application == 'overseerr') {
            if ($cmd->getLogicalId() == "day_pending") {
               $pendingRawCmd = $this->getCmd(null, 'day_pending_raw');
               if ($pendingRawCmd != null) {
                  $pendingList = json_decode($pendingRawCmd->execCmd(), true);
                  if (is_array($pendingList)) {
                     if (count($pendingList) != 0) {
                        $pendingRules = $this->getConfigurationFor($this, "dayPending", "maxPending");
                        $maxItemToDisplay=$pendingRules['numberMax'];
                        $pendingList = sonarrUtils::applyMaxToArray($pendingList, $maxItemToDisplay);
                        $html = $html . $this->addCmdName($cmd, $_version, "Demandes en attente");
                        //if ($condensed == 0) {
                           $html = $html . $this->generateHtmlForDatas($pendingList, $_version, $application, false);
                        //} else {
                           //$html = $html . $this->generateHtmlForDatasCondensed($pendingList, $_version, $application, true);
                        //}
                     } else {
                        $html = $html . $this->addCmdName($cmd, $_version, "Pas de demande en cours");
                     }
                  }
               } else {
                  LogSonarr::error('missing cmd day_pending_raw');
      }
               $html = $html . "</div>";
            }
            //if ($condensed == 0) {
               if ($cmd->getLogicalId() == "day_approved") {
                  $approvedRawCmd = $this->getCmd(null, 'day_approved_raw');
                  if ($approvedRawCmd != null) {
                     $approvedList = json_decode($approvedRawCmd->execCmd(), true);
                     if (is_array($approvedList)) {
                        if (count($approvedList) != 0) {
                           $html = $html . $this->addCmdName($cmd, $_version, "Demandes acceptées");
                           // $approvedRules = $this->getConfigurationFor($this, "dayApproved", "maxApproved");
                           $maxItemToDisplay= max($maxItemToDisplay-count($pendingList),0);
                           // $maxItemToDisplay=$approvedRules['numberMax'];
                           $approvedList = sonarrUtils::applyMaxToArray($approvedList, $maxItemToDisplay);
                           $html = $html . $this->generateHtmlForDatas($approvedList, $_version, $application, true);
                        }
                     }
                  } else {
                     LogSonarr::error('missing cmd day_approved_raw');
                  }
                  $html = $html . "</div>";
               }
               /*if ($cmd->getLogicalId() == "day_declined") {
                  $declinedRawCmd = $this->getCmd(null, 'day_declined_raw');
                  if ($declinedRawCmd != null) {
                     $html = $html . $this->addCmdName($cmd, $_version, "Demandes rejetées");
                     $declinedList = json_decode($declinedRawCmd->execCmd(), true);
                     if (is_array($declinedList)) {
                        $declinedList = sonarrUtils::applyMaxToArray($declinedList, 3);
                        $html = $html . $this->generateHtmlForDatas($declinedList, $_version, $application, false);
                     }
                  } else {
                     LogSonarr::error('missing cmd day_declined_raw');
                  }
                  $html = $html . "</div>";
               }*/
            //}
         }
         // Fin Tonio 

      }
      $replace["#cmds#"] = $html;
      if ($application == 'sonarr') {
         $url = $this->getConfiguration('sonarrUrl');
         $replace["#server_url#"] = $url;
      } else if ($application == 'radarr') {
         $url = $this->getConfiguration('radarrUrl');
         $replace["#server_url#"] = $url;
      }
      // Start Tonio
      else if ($application == 'overseerr') {
         $url = $this->getConfiguration('overseerrUrl');
         $replace["#server_url#"] = $url;
      }
      // Fin Tonio
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