<?php
/** Retention removes evidence only; the signed case history remains intact. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(array_diff(array_slice($argv,1),['--apply','--help'])){fwrite(STDERR,"Unknown option.\n");exit(2);}
if(in_array('--help',$argv,true)){echo "Usage: php scripts/purge_report_evidence.php [--apply]\nDefault is a dry run. Removes evidence from terminal cases older than configured retention.\n";exit;}
require_once dirname(__DIR__).'/config.php';
$db=connectDb();$days=reportSettingInt('CONDO_REPORT_EVIDENCE_RETENTION_DAYS',365,1,3650);$apply=in_array('--apply',$argv,true);
$cases=$db->query("SELECT DISTINCT r.id FROM community_reports r JOIN report_attachments a ON a.report_id=r.id WHERE r.status IN ('closed','declined','withdrawn') AND r.closed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL $days DAY) AND a.deleted_at IS NULL")->fetch_all(MYSQLI_ASSOC);$count=0;
foreach($cases as$row){
    $db->begin_transaction();
    try{
        $s=$db->prepare("SELECT * FROM community_reports WHERE id=? AND status IN ('closed','declined','withdrawn') AND closed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL $days DAY) FOR UPDATE");$s->bind_param('i',$row['id']);$s->execute();$case=$s->get_result()->fetch_assoc();
        if(!$case){$db->rollback();continue;}
        if(!reportVerifyAudit($db,$case))throw new RuntimeException('Integrity review required; retention skipped.');
        $s=$db->prepare('SELECT id,stored_name FROM report_attachments WHERE report_id=? AND deleted_at IS NULL');$s->bind_param('i',$row['id']);$s->execute();$ids=[];
        foreach($s->get_result()as$file){$count++;if(!$apply)continue;$path=reportEvidencePath($file['stored_name']);if($path&&!unlink($path))throw new RuntimeException('Private evidence could not be removed.');$ids[]=(int)$file['id'];}
        if($apply&&$ids){$db->query('UPDATE report_attachments SET deleted_at=UTC_TIMESTAMP() WHERE id IN ('.implode(',',$ids).')');reportAppendEvent($db,$case,hash('sha256',random_bytes(32)),'evidence_retention','internal','system',null,'Evidence removed under the configured retention policy.',null,null,['attachment_ids'=>$ids,'retention_days'=>$days]);$db->query('UPDATE community_reports SET version=version+1 WHERE id='.(int)$case['id']);$db->commit();}else $db->rollback();
    }catch(Throwable $error){$db->rollback();fwrite(STDERR,"Retention stopped; review private storage and the case audit history before retrying.\n");exit(1);}
}
echo ($apply?'Removed':'Eligible for deletion').": $count evidence file(s); retention $days days after terminal closure.\n";
