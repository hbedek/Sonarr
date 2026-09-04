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
 * along with Jeedom. If not, see <http://gnu.org>.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php'; //

function sonarr_install() //
{
  // 1. Mise à jour ou installation des dépendances Guzzle via Composer
  log::add('sonarr', 'info', 'Vérification et installation des dépendances (GuzzleHttp)...');
  
  $resourcesDir = dirname(__FILE__) . '/../resources';
  
  // Créer le dossier resources s'il n'existe pas
  if (!is_dir($resourcesDir)) {
      mkdir($resourcesDir, 0775, true);
  }

  // Créer un fichier composer.json à la volée s'il n'existe pas
  $composerJsonPath = $resourcesDir . '/composer.json';
  if (!file_exists($composerJsonPath)) {
      $composerConfig = [
          "require" => [
              "guzzlehttp/guzzle" => "^7.0"
          ],
          "config" => [
              "vendor-dir" => "vendor"
          ]
      ];
      file_put_contents($composerJsonPath, json_encode($composerConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  // Déterminer la commande Composer à utiliser (globale ou téléchargée en local)
  $composerCmd = 'composer';
  exec('command -v composer', $out, $status);
  
  if ($status !== 0) {
      log::add('sonarr', 'info', 'Composer non trouvé globalement. Téléchargement d\'une version locale...');
      // Téléchargement temporaire de composer.phar dans le dossier resources
      exec('cd ' . escapeshellarg($resourcesDir) . ' && curl -sS https://getcomposer.org | php', $out, $status);
      if ($status === 0 && file_exists($resourcesDir . '/composer.phar')) {
          $composerCmd = 'php composer.phar';
      } else {
          log::add('sonarr', 'error', 'Impossible de télécharger Composer en local. Échec de l\'installation.');
          return;
      }
  }

  // Exécution de l'installation en forçant le dossier COMPOSER_HOME dans le répertoire du plugin
  log::add('sonarr', 'info', 'Exécution de composer install...');
  $composerHome = escapeshellarg($resourcesDir . '/.composer');
  $cmd = 'export COMPOSER_HOME=' . $composerHome . ' && cd ' . escapeshellarg($resourcesDir) . ' && ' . $composerCmd . ' install --no-dev --optimize-autoloader --no-interaction 2>&1';
  exec($cmd, $output, $resultCode);


  if ($resultCode === 0) {
      log::add('sonarr', 'info', 'Dépendances GuzzleHttp installées avec succès.');
      // Nettoyage optionnel du composer.phar local s'il a été utilisé
      if (file_exists($resourcesDir . '/composer.phar')) {
          unlink($resourcesDir . '/composer.phar');
      }
  } else {
      log::add('sonarr', 'error', 'Erreur lors du composer install : ' . implode("\n", $output));
  }
  
}

function sonarr_update() //
{
  // 1. Mise à jour ou installation des dépendances Guzzle via Composer
  log::add('sonarr', 'info', 'Vérification et installation des dépendances (GuzzleHttp)...');
  
  $resourcesDir = dirname(__FILE__) . '/../resources';
  
  // Créer le dossier resources s'il n'existe pas
  if (!is_dir($resourcesDir)) {
      mkdir($resourcesDir, 0775, true);
  }

  // Créer un fichier composer.json à la volée s'il n'existe pas
  $composerJsonPath = $resourcesDir . '/composer.json';
  if (!file_exists($composerJsonPath)) {
      $composerConfig = [
          "require" => [
              "guzzlehttp/guzzle" => "^7.0"
          ],
          "config" => [
              "vendor-dir" => "vendor"
          ]
      ];
      file_put_contents($composerJsonPath, json_encode($composerConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  // Déterminer la commande Composer à utiliser (globale ou téléchargée en local)
  $composerCmd = 'composer';
  exec('command -v composer', $out, $status);
  
  if ($status !== 0) {
      log::add('sonarr', 'info', 'Composer non trouvé globalement. Téléchargement d\'une version locale...');
      // Téléchargement temporaire de composer.phar dans le dossier resources
      exec('cd ' . escapeshellarg($resourcesDir) . ' && curl -sS https://getcomposer.org | php', $out, $status);
      if ($status === 0 && file_exists($resourcesDir . '/composer.phar')) {
          $composerCmd = 'php composer.phar';
      } else {
          log::add('sonarr', 'error', 'Impossible de télécharger Composer en local. Échec de l\'installation.');
          return;
      }
  }

  // Exécution de l'installation en forçant le dossier COMPOSER_HOME dans le répertoire du plugin
  log::add('sonarr', 'info', 'Exécution de composer install...');
  $composerHome = escapeshellarg($resourcesDir . '/.composer');
  $cmd = 'export COMPOSER_HOME=' . $composerHome . ' && cd ' . escapeshellarg($resourcesDir) . ' && ' . $composerCmd . ' install --no-dev --optimize-autoloader --no-interaction 2>&1';
  exec($cmd, $output, $resultCode);


  if ($resultCode === 0) {
      log::add('sonarr', 'info', 'Dépendances GuzzleHttp installées avec succès.');
      // Nettoyage optionnel du composer.phar local s'il a été utilisé
      if (file_exists($resourcesDir . '/composer.phar')) {
          unlink($resourcesDir . '/composer.phar');
      }
  } else {
      log::add('sonarr', 'error', 'Erreur lors du composer install : ' . implode("\n", $output));
  }

  // 2. Traitement d'origine du plugin (Création des commandes)
  foreach (eqLogic::byType('sonarr') as $sonarr) { //
    $sonarr->createCmdIfNeeded(); //
  }
}

function sonarr_remove() //
{
  // Optionnel : Supprimer le dossier vendor lors de la suppression du plugin
  $vendorDir = dirname(__FILE__) . '/../resources/vendor';
  if (is_dir($vendorDir)) {
      exec('rm -rf ' . escapeshellarg($vendorDir));
  }
}
