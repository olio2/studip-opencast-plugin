<?php

class AccessControl extends Migration
{

    function up()
    {
        DBManager::get()->exec("CREATE TABLE IF NOT EXISTS `oc_access_control` (
            `id` TEXT NOT NULL ,
            `type` ENUM('episode','series') NOT NULL ,
            `course_id` VARCHAR(32) NOT NULL ,
            `acl_id` INT NOT NULL,
            PRIMARY KEY `acl_id` (`acl_id`);
        )");

        $result = DBManager::get()->query("SELECT seminar_id, episode_id FROM `oc_seminar_episodes`
            WHERE visible = 'false'");
        $data = $result->fetchAll(PDO::FETCH_KEY_PAIR);

        DBManager::get()->exec("ALTER TABLE `oc_seminar_episodes`
            MODIFY `visible` ENUM('invisible', 'visible', 'free') NOT NULL DEFAULT 'visible'");

        DBManager::get()->exec("UPDATE `oc_seminar_episodes`
            SET visible = 'visible'
            WHERE 1");

        $stmt = DBManager::get()->prepare("UPDATE `oc_seminar_episodes`
            SET visible = 'invisible'
            WHERE seminar_id = ?
                AND episode_id = ?");

        foreach ($data as $course_id => $episode_id) {
            $stmt->execute([$course_id, $episode_id]);
        }

        // Expire orm cache, so the change can take effect
        SimpleORMap::expireTableScheme();
    }

    function down()
    {
        DBManager::get()->query("DROP TABLE `oc_access_control`");

        $result = DBManager::get()->query("SELECT seminar_id, episode_id FROM `oc_seminar_episodes`
            WHERE visible = 'invisible'");
        $data = $result->fetchAll(PDO::FETCH_KEY_PAIR);

        DBManager::get()->query("ALTER TABLE `oc_seminar_episodes`
            MODIFY `visible` ENUM('true', 'false') NOT NULL DEFAULT 'true'");

        DBManager::get()->exec("UPDATE `oc_seminar_episodes`
            SET visible = 'true'
            WHERE 1");

        $stmt = DBManager::get()->prepare("UPDATE `oc_seminar_episodes`
            SET visible = 'false'
            WHERE seminar_id = ?
                AND episode_id = ?");

        foreach ($data as $course_id => $episode_id) {
            $stmt->execute([$course_id, $episode_id]);
        }

        // Expire orm cache, so the change can take effect
        SimpleORMap::expireTableScheme();
    }

}
