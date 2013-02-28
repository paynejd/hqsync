-- MySQL dump 10.13  Distrib 5.5.15, for osx10.6 (i386)
--
-- Host: localhost    Database: hqsync
-- ------------------------------------------------------
-- Server version	5.5.15

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Create database for hqsync
create database if not exists hqsync;
use hqsync;

-- Create the HqSync application user
create user 'hqsync'@'localhost' identified by 'hqdtree1';
grant all on hqsync.* to 'hqsync'@'localhost';

-- This is required to do the LOAD DATA INFILE command
grant file on *.* to 'hqsync'@'localhost';

-- Create database and grant permissions to sync a new domain
create database if not exists pathfinder;
grant all on pathfinder.* to 'hqsync'@'localhost';


--
-- Table structure for table `hqsync`
--

DROP TABLE IF EXISTS `hqsync`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hqsync` (
  `hqsync_id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(250) NOT NULL,
  `dbname` varchar(250) NOT NULL,
  `url` varchar(1000) DEFAULT NULL,
  `form_name` varchar(250) NOT NULL,
  `uid` varchar(100) NOT NULL,
  `pwd` varchar(100) NOT NULL,
  `active` int(1) NOT NULL DEFAULT '1',
  `use_token` int(1) NOT NULL DEFAULT '1',
  `purge_before_import` int(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`hqsync_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hqsync`
--

LOCK TABLES `hqsync` WRITE;
/*!40000 ALTER TABLE `hqsync` DISABLE KEYS */;
INSERT INTO `hqsync` VALUES (1,'pathfinder','pathfinder','https://www.commcarehq.org/a/pathfinder/reports/export/?export_tag=%22http://dev.commcarehq.org/Pathfinder/pathfinder_cc_ref_resolv%22&format=csv','ref_resolv','paynejd@gmail.com','',1,1,0),(2,'pathfinder','pathfinder','https://www.commcarehq.org/a/pathfinder/reports/export/?export_tag=%22http://dev.commcarehq.org/Pathfinder/pathfinder_cc_batch_survey%22&format=csv','batch_survey','paynejd@gmail.com','',1,1,0),(3,'pathfinder','pathfinder','https://www.commcarehq.org/a/pathfinder/reports/export/?export_tag=%22http://dev.commcarehq.org/Pathfinder/pathfinder_cc_followup%22&format=csv','followup','paynejd@gmail.com','',1,1,0),(4,'pathfinder','pathfinder','https://www.commcarehq.org/a/pathfinder/reports/export/?export_tag=%22http://dev.commcarehq.org/Pathfinder/pathfinder_cc_reg%22&format=csv','reg','paynejd@gmail.com','',1,1,0),(5,'pathfinder','pathfinder','https://www.commcarehq.org/a/pathfinder/reports/export/?export_tag=%22http://dev.commcarehq.org/Pathfinder/pathfinder_hbcp_status%22&format=csv','hbcp_status','paynejd@gmail.com','',1,1,0),(6,'pathfinder','pathfinder','https://www.commcarehq.org/a/pathfinder/reports/export/?export_tag=%22http://dev.commcarehq.org/pathfinder/fpform%22&format=csv','fpform','paynejd@gmail.com','',0,1,0),(7,'pathfinder','pathfinder','https://www.commcarehq.org/a/pathfinder/reports/export/?export_tag=%22http://openrosa.org/user-registration%22&format=csv','user_registration','paynejd@gmail.com','',1,1,0),(8,'pathfinder','pathfinder','https://www.commcarehq.org/a/pathfinder/reports/download/cases/?include_closed=false','case','paynejd@gmail.com','',1,0,1);
/*!40000 ALTER TABLE `hqsync` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hqsync_log`
--

DROP TABLE IF EXISTS `hqsync_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hqsync_log` (
  `hqsync_log_id` int(11) NOT NULL AUTO_INCREMENT,
  `hqsync_id` int(11) NOT NULL,
  `input_token` varchar(100) DEFAULT NULL,
  `output_token` varchar(100) DEFAULT NULL,
  `sync_time` datetime DEFAULT NULL,
  `sync_status` int(11) NOT NULL,
  `message` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`hqsync_log_id`),
  KEY `hqsync_id` (`hqsync_id`)
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `hqsync_table`
--

DROP TABLE IF EXISTS `hqsync_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hqsync_table` (
  `hqsync_table_id` int(11) NOT NULL AUTO_INCREMENT,
  `hqsync_id` int(11) NOT NULL,
  `filename` varchar(250) NOT NULL,
  `tablename` varchar(250) DEFAULT NULL,
  `include_in_import` int(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`hqsync_table_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;


/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2011-09-20 10:22:43


