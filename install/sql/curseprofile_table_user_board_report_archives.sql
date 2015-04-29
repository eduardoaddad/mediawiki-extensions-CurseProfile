CREATE TABLE /*_*/user_board_report_archives (
  `ra_id` int(11) NOT NULL AUTO_INCREMENT,
  `ra_comment_id` int(11) NOT NULL DEFAULT '0',
  `ra_curse_id_from` int(11) NOT NULL DEFAULT '0',
  `ra_comment_text` text NOT NULL,
  `ra_last_edited` datetime DEFAULT NULL,
  `ra_first_reported` datetime DEFAULT NULL,
  `ra_action_taken` int(1) DEFAULT '0',
  `ra_action_taken_at` datetime DEFAULT NULL,
  `ra_action_taken_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`ra_id`),
  UNIQUE KEY `ra_comment_report` (`ra_comment_id`,`ra_last_edited`),
  KEY `ra_curse_id_from` (`ra_curse_id_from`),
  KEY `ra_first_reported` (`ra_first_reported`)
) /*$wgDBTableOptions*/;