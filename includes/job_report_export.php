<?php
/** Wrap at word boundaries, falling back to Unicode characters for long tokens. */
function jrWrapLines(string $text,callable $fits): array {
    $lines=[];
    foreach(explode("\n",str_replace(["\r","\t"],['','    '],$text))as$paragraph){
        $line='';foreach(preg_split('/(\s+)/u',$paragraph,-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY)as$word){
            if($fits($line.$word)){$line.=$word;continue;}
            if(trim($line)!==''){$lines[]=rtrim($line);$line='';}
            $word=ltrim($word);
            foreach(preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY)as$character){if($line!==''&&!$fits($line.$character)){$lines[]=$line;$line='';}$line.=$character;}
        }$lines[]=rtrim($line);
    }return $lines;
}
function jrExportName(array $data,string $extension): string {
    $id=preg_replace('/[^A-Za-z0-9_-]/','',$data['staff']['staff_id']);return 'JobReport_'.$id.'_'.$data['period']['period'].'.'.$extension;
}
function jrExportHtml(array $data,?array $filed=null): string {
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$role=getUserRoles()[$data['staff']['role']]??$data['staff']['role'];
    $html='<!doctype html><html><head><meta charset="utf-8"><style>'.brandDocumentStyles().'@page{margin:38px 38px 46px}body{font-family:"DejaVu Sans",sans-serif;font-size:10px;color:#182236;background:#fff}h1{font-size:23px;margin:20px 0 6px}h2{font-size:14px;margin:20px 0 8px;color:#b45309}p{line-height:1.6}table.activities{width:100%;border-collapse:collapse;table-layout:fixed}thead{display:table-header-group}tr{page-break-inside:avoid}th,td{padding:7px;border-bottom:1px solid #dde3eb;text-align:left;vertical-align:top;word-wrap:break-word}th{background:#f2f4f8;font-size:9px}small,.muted{color:#58657a;font-size:9px}.note{white-space:normal;line-height:1.65;word-wrap:break-word}.rule{border-top:2px solid #d97706;margin:15px 0}.summary{background:#f5f7fa;padding:12px}.date{color:#b45309;background:#fff7ed}.label{color:#b45309;font-size:9px}</style></head><body>'.brandDocumentHtml(34).'<div class="rule"></div><h1>'.ucfirst($data['period']['type']).' Job Report</h1><p>'.$e($data['staff']['name']).' · '.$e($data['staff']['staff_id']).' · '.$e($role).'<br>Reporting period: '.$e($data['period']['label']).' (Asia/Manila)<br>Generated: '.$e((new DateTimeImmutable($data['generated_at']??gmdate('Y-m-d H:i:s'),new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A')).'</p>';
    if($filed)$html.='<p>Reference: '.$e($filed['reference']).' · Version '.$e($filed['version']).' · '.$e(JR_STATUSES[$filed['status']]).'</p>';
    $s=$data['summary'];$html.='<div class="summary">Total Activities: '.$s['total'].' &nbsp; Completed Actions: '.$s['completed'].' &nbsp; Updated Records: '.$s['updated'].'</div><p class="muted">'.$e($data['definitions']).'</p>';
    foreach($data['metrics']??[]as$metric)$html.='<p>'.$e($metric['label']).': '.$e($metric['value']).'</p>';
    $html.='<h2>System-Recorded Activity</h2>';
    if(!$data['activities'])$html.='<p>No system-recorded work activities for this period.</p>';
    else{
        $html.='<table class="activities"><thead><tr><th style="width:18%">Time (PH)</th><th style="width:17%">Module</th><th style="width:34%">Work activity</th><th style="width:20%">Reference</th><th style="width:11%">Result</th></tr></thead><tbody>';$day='';
        foreach($data['activities'] as $r){$date=substr($r['local_time'],0,10);if($data['period']['type']==='monthly'&&$date!==$day){$html.='<tr><td colspan="5" class="date">'.$e($date).'</td></tr>';$day=$date;}$html.='<tr><td>'.$e($r['local_time']).'</td><td>'.$e($r['module']).'</td><td>'.$e($r['description']).'</td><td>'.$e($r['reference']).'</td><td>'.$e($r['result']).'</td></tr>';}$html.='</tbody></table>';
    }
    $html.='<h2>Staff-Reported Work Notes</h2><p class="muted">These notes are supplied by the staff member and are separate from verified system records.</p>';
    foreach(JR_NOTES as$key=>$label){$html.='<h2>'.$e($label).'</h2>';foreach(jrWrapLines($data['notes'][$key]?:'No notes provided.',static fn($s)=>mb_strlen($s)<=90)as$line)$html.='<div class="note">'.($line===''?'&nbsp;':$e($line)).'</div>';}
    if($filed){$html.='<h2>Submission & Review History</h2>';foreach($filed['reviews']as$r){$html.='<p>'.$e($r['created_at']).' UTC · '.$e($r['actor_name']).' · '.$e(JR_STATUSES[$r['action']]??$r['action']).'</p>';if($r['feedback']!=='')foreach(jrWrapLines($r['feedback'],static fn($s)=>mb_strlen($s)<=90)as$line)$html.='<div class="note">'.$e($line).'</div>';}}
    return $html.'<p class="muted">The Celandine · Staff activity report · Confidential — authorized staff use only</p></body></html>';
}
function jrPdf(array $data,?array $filed=null): string {
    $options=new Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('defaultFont','DejaVu Sans');$options->setChroot(dirname(__DIR__));
    $pdf=new Dompdf\Dompdf($options);$pdf->loadHtml(jrExportHtml($data,$filed),'UTF-8');$pdf->setPaper('A4');$pdf->render();brandPdfFooter($pdf, 'Confidential staff report');return $pdf->output();
}
/** Fixed-size white paper PNG pages; no browser screenshots or public temp files. */
function jrPngPages(array $data,?array $filed=null): Generator {
    $exportGenerated = brandGeneratedAt();
    if(!extension_loaded('gd'))throw new RuntimeException('PNG export requires the existing PHP GD extension.');
    $font=dirname(__DIR__).'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';$bold=dirname(__DIR__).'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
    if(!is_file($font)||!is_file($bold))throw new RuntimeException('The document font is unavailable.');
    $lines=[];$push=function(string $text,int $size=17,bool $heavy=false,string $color='ink')use(&$lines,$font,$bold):void{
        $fits=static function(string $line)use($size,$heavy,$bold,$font):bool{$bounds=imagettfbbox($size,0,$heavy?$bold:$font,$line);return $bounds[2]-$bounds[0]<=1080;};
        foreach(jrWrapLines($text,$fits)as$line)$lines[]=[$line,$size,$heavy,$color];
    };
    $push(ucfirst($data['period']['type']).' Job Report',27,true);$push($data['staff']['name'].' | '.$data['staff']['staff_id'].' | '.(getUserRoles()[$data['staff']['role']]??$data['staff']['role']));$push('Period: '.$data['period']['label'].' (Asia/Manila)');
    $push('Generated: '.(new DateTimeImmutable($data['generated_at']??gmdate('Y-m-d H:i:s'),new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A'));
    if($filed)$push($filed['reference'].' | Version '.$filed['version'].' | '.JR_STATUSES[$filed['status']]);
    $s=$data['summary'];$push('Total Activities: '.$s['total'].' | Completed Actions: '.$s['completed'].' | Updated Records: '.$s['updated'],17,true);$push($data['definitions'],14,false,'muted');foreach($data['metrics']??[]as$metric)$push($metric['label'].': '.$metric['value'],16,true);$push('');$push('SYSTEM-RECORDED ACTIVITIES',20,true,'orange');
    if(!$data['activities'])$push('No system-recorded work activities for this period.');
    $date='';foreach($data['activities']as$r){$d=substr($r['local_time'],0,10);if($data['period']['type']==='monthly'&&$date!==$d){$push($d,19,true,'orange');$date=$d;}$push($r['local_time'].' | '.$r['module'].' | '.$r['result'],16,true);$push($r['description'].' | '.$r['reference'],16);$push('',9);}
    $push('');$push('STAFF-REPORTED WORK NOTES',20,true,'orange');$push('Staff-supplied notes are separate from verified system records.',14,false,'muted');
    foreach(JR_NOTES as$key=>$label){$push($label,18,true);$push($data['notes'][$key]?:'No notes provided.');$push('',10);}
    if($filed){$push('SUBMISSION & REVIEW HISTORY',20,true,'orange');foreach($filed['reviews']as$r){$push($r['created_at'].' UTC | '.$r['actor_name'].' | '.(JR_STATUSES[$r['action']]??$r['action']),16,true);if($r['feedback']!=='')$push($r['feedback']);}}
    $chunks=[];$chunk=[];$height=0;
    foreach($lines as$index=>$line){
        $h=(int)ceil($line[1]*1.65);$required=$h;
        // Keep a section/date/record heading with its following content.
        if($line[2])for($next=$index+1;$next<count($lines);$next++){$required+=(int)ceil($lines[$next][1]*1.65);if(!$lines[$next][2])break;}
        if($chunk&&$height+min(1440,$required)>1440){$chunks[]=$chunk;$chunk=[];$height=0;}
        $chunk[]=$line;$height+=$h;
    }
    if($chunk)$chunks[]=$chunk;
    foreach($chunks as$index=>$chunk){$im=imagecreatetruecolor(1240,1754);$colors=['ink'=>imagecolorallocate($im,24,34,54),'muted'=>imagecolorallocate($im,86,101,122),'orange'=>imagecolorallocate($im,180,83,9)];imagefill($im,0,0,imagecolorallocate($im,255,255,255));
        brandImageHeader($im);
        $y=185;foreach($chunk as[$text,$size,$heavy,$color]){imagettftext($im,$size,0,80,$y,$colors[$color],$heavy?$bold:$font,$text);$y+=(int)ceil($size*1.65);}
        brandImageFooter($im,$index+1,count($chunks),'Confidential',$exportGenerated);ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);yield ['number'=>$index+1,'total'=>count($chunks),'bytes'=>$bytes];
    }
}
function jrExportDownload(array $data,string $format,?array $filed=null): void {
    header('Cache-Control: no-store, private, max-age=0');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');
    if($format==='pdf'){$bytes=jrPdf($data,$filed);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.jrExportName($data,'pdf').'"');echo $bytes;return;}
    if($format!=='png')throw new DomainException('Choose PDF or PNG.');
    $pages=jrPngPages($data,$filed);$pages->rewind();$first=$pages->current();
    if($first['total']===1){header('Content-Type: image/png');header('Content-Disposition: attachment; filename="'.jrExportName($data,'png').'"');echo $first['bytes'];return;}
    if(!class_exists('ZipArchive'))throw new RuntimeException('Multi-page PNG export requires PHP ZIP.');
    $directory=realpath(sys_get_temp_dir());
    foreach([dirname(__DIR__),dirname(__DIR__,2),$_SERVER['DOCUMENT_ROOT']??'']as$public){if($directory&&$public&&realpath($public)&&visitorPathWithin($directory,realpath($public)))throw new RuntimeException('PNG export staging must be outside public directories.');}
    if(!$directory)throw new RuntimeException('Private export staging is unavailable.');
    $tmp=tempnam($directory,'condo-jr-');if(!$tmp)throw new RuntimeException('Private export staging is unavailable.');
    $open=false;$zip=new ZipArchive();
    try{
        if(!chmod($tmp,0600))throw new RuntimeException('Unable to protect PNG export staging.');
        if($zip->open($tmp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Unable to prepare PNG pages.');$open=true;
        foreach($pages as$page){$name=jrExportName($data,'');$name=rtrim($name,'.').'_page_'.str_pad((string)$page['number'],3,'0',STR_PAD_LEFT).'.png';if(!$zip->addFromString($name,$page['bytes']))throw new RuntimeException('Unable to include every PNG page.');}
        if(!$zip->close())throw new RuntimeException('Unable to finish PNG archive.');$open=false;
        header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.jrExportName($data,'zip').'"');readfile($tmp);
    }finally{
        if($open){try{$zip->close();}catch(Throwable $ignored){error_log('Unable to close private job report staging.');}}
        if(is_file($tmp)&&!unlink($tmp))error_log('Unable to clean private job report staging.');
    }
}
