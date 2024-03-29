<?php
require_once __DIR__  . '/LogSonarr.php';

class SonarrRadarrUtils
{

    public static function verifyConfiguration($context, $name)
    {
        $configuration = $context->getConfiguration($name);
        if ($configuration == '') {
            LogSonarr::error('Configuration ' . $name . ' not set for ' . $context->getName());
            return null;
        }
        return $configuration;
    }

    public static function verifyCmd($context, $name)
    {
        $cmdToVerify = $context->getCmd(null, $name);
        if (!is_object($cmdToVerify)) {
            LogSonarr::error('Missing ' . $name . ' cmd for ' . $context->getName() . ' try SAVING equipment to create missings cmds');
            return null;
        }
        return $cmdToVerify;
    }

    public static function retrieveValueFromCmdConfig($context, $cmdConfigStr)
    {
        $cmd = LightUtils::retrieveCmdFromConfig($context, $cmdConfigStr);
        if ($cmd != null) {
            return $cmd->execCmd();
        }
        return null;
    }

    public static function retrieveCmdFromConfig($context, $cmdConfigStr)
    {
        $cmdConfig = $context->getConfiguration($cmdConfigStr);
        $cmd = cmd::byId(str_replace('#', '', $cmdConfig));
        if (is_object($cmd)) {
            return $cmd;
        }
        return null;
    }
    public static function retrieveTagsFromCmd($context, $tagsWanted)
    {
        $tagsToReturn = [];
        $tagsResultRawCmd = SonarrRadarrUtils::verifyCmd($context, 'tags_result_raw');
        if ($tagsResultRawCmd == null) {
            return $tagsToReturn;
        }
        $tagsResultRaw = json_decode($tagsResultRawCmd->execCmd(), true)['tags'];
        $tagsRaw = new Tags(json_encode($tagsResultRaw));
        foreach ($tagsRaw->tags as $tagRaw) {
            foreach ($tagsWanted as $tagWanted) {
                if ($tagWanted == $tagRaw->label) {
                    array_push($tagsToReturn, $tagRaw->id);
                }
            }
        }
        return $tagsToReturn;
    }
}
