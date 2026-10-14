<?php
function reportFileLimits(): array {return ['files'=>reportSettingInt('CONDO_REPORT_MAX_FILES',5,1,10),'bytes'=>reportSettingInt('CONDO_REPORT_MAX_FILE_MB',10,1,10)*1048576,'total_bytes'=>reportSettingInt('CONDO_REPORT_MAX_TOTAL_MB',30,1,60)*1048576];}
function reportUploadFiles(array $files): array {
    if(!isset($files['name']))return [];
    if(!is_array($files['name']))throw new InvalidArgumentException('Use the evidence file selector.');$out=[];
    foreach($files['name']as$i=>$name){if(($files['error'][$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;$out[]=['name'=>$name,'type'=>$files['type'][$i]??'','tmp_name'=>$files['tmp_name'][$i]??'','error'=>$files['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$files['size'][$i]??0];}return $out;
}
function reportEvidencePath(string $name): ?string {
    if(!preg_match('/^[a-f0-9]{48}\.(?:jpg|pdf)$/D',$name))return null;$root=reportStorageDirectory();$path=realpath($root.'/'.$name);
    return $path&&visitorPathWithin($path,$root)&&is_file($path)?$path:null;
}
function reportRemoveStaged(array $files): void {foreach($files as$file)if(($path=reportEvidencePath($file['stored_name'])))unlink($path);}
function reportStageEvidence(array $uploads,bool $anonymous): array {
    $limits=reportFileLimits();if(count($uploads)>$limits['files'])throw new InvalidArgumentException('Too many evidence files.');
    $total=0;$stored=[];$pendingPath=null;
    try{foreach($uploads as$index=>$upload){
        if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($upload['tmp_name']??''))throw new InvalidArgumentException('An evidence upload failed. Select the files again.');
        $bytes=filesize($upload['tmp_name']);$total+=$bytes;if(!$bytes||$bytes>$limits['bytes']||$total>$limits['total_bytes'])throw new InvalidArgumentException('Evidence exceeds the permitted file or combined size.');
        $name=str_replace('\\','/',(string)$upload['name']);$extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));$mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $allowed=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf'];
        if(!isset($allowed[$extension])||$mime!==$allowed[$extension])throw new InvalidArgumentException('Evidence must contain valid JPG, PNG, WebP or PDF data matching its extension.');
        $safeName=bin2hex(random_bytes(24)).($mime==='application/pdf'?'.pdf':'.jpg');$path=reportStorageDirectory().'/'.$safeName;$pendingPath=$path;
        if($mime==='application/pdf'){
            $data=file_get_contents($upload['tmp_name']);
            if(!preg_match('/^%PDF-(?:1\.[0-7]|2\.0)/',$data)||!preg_match('/%%EOF\s*$/D',$data)||!preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/D',$data,$xref)||!preg_match('/\/Root\s+\d+\s+\d+\s+R\b/',$data)||!str_contains($data,'endobj'))throw new InvalidArgumentException('The PDF is incomplete or cannot be validated.');
            $offset=(int)$xref[1];$cross=substr($data,$offset,250);
            if($offset<1||$offset>=strlen($data)||(!str_starts_with($cross,'xref')&&!preg_match('/^\d+\s+\d+\s+obj\s*<<.*?\/Type\s*\/XRef/s',$cross)))throw new InvalidArgumentException('The PDF cross-reference is invalid.');
            // Evidence PDFs are download-only. Reject active/encrypted content;
            // the file is never executed, rendered or decompressed on the server.
            $keywords=preg_replace_callback('/#([a-f0-9]{2})/i',static fn($m)=>chr(hexdec($m[1])),$data);
            if(preg_match('~/\s*(?:JavaScript|JS|Launch|OpenAction|AA|EmbeddedFile|RichMedia|XFA|Encrypt)\b~i',$keywords))throw new InvalidArgumentException('Use a plain PDF without scripts, embedded files or encryption.');
            if(!move_uploaded_file($upload['tmp_name'],$path))throw new RuntimeException('Evidence storage failed.');
        }else{
            $info=@getimagesize($upload['tmp_name']);if(!$info||$info['mime']!==$mime||max($info[0],$info[1])>8192||$info[0]*$info[1]>8388608)throw new InvalidArgumentException('Use readable images up to 8 megapixels and 8192 pixels per dimension.');
            if(!extension_loaded('gd'))throw new RuntimeException('Image processing is unavailable.');
            $source=@imagecreatefromstring((string)file_get_contents($upload['tmp_name']));if(!$source)throw new InvalidArgumentException('The evidence image is corrupted.');
            $scale=min(1,2048/max($info[0],$info[1]));$w=max(1,(int)round($info[0]*$scale));$h=max(1,(int)round($info[1]*$scale));$image=imagecreatetruecolor($w,$h);
            try{imagefill($image,0,0,imagecolorallocate($image,255,255,255));imagecopyresampled($image,$source,0,0,0,0,$w,$h,$info[0],$info[1]);if(!imagejpeg($image,$path,90)){if(is_file($path))unlink($path);throw new RuntimeException('Evidence image could not be saved.');}}finally{imagedestroy($source);imagedestroy($image);}
            $mime='image/jpeg';
        }
        if(!chmod($path,0600))throw new RuntimeException('Private evidence permissions could not be protected.');
        $contentHash=hash_file('sha256',$path);if(!is_string($contentHash)||strlen($contentHash)!==64)throw new RuntimeException('Evidence digest could not be recorded.');
        $display=$anonymous?'Evidence '.($index+1).($mime==='application/pdf'?'.pdf':'.jpg'):mb_substr(preg_replace('/[\x00-\x1F\x7F]/','',basename($name)),0,140);
        $stored[]=['stored_name'=>$safeName,'display_name'=>$display,'mime'=>$mime,'byte_size'=>filesize($path),'content_hash'=>$contentHash];$pendingPath=null;
    }return $stored;}catch(Throwable $error){if($pendingPath&&is_file($pendingPath))unlink($pendingPath);reportRemoveStaged($stored);throw $error;}
}
function reportAttachEvidence(mysqli $db,int $id,int $event,array $files): void {
    $s=$db->prepare('INSERT INTO report_attachments(report_id,event_id,stored_name,display_name,mime,byte_size,content_hash) VALUES(?,?,?,?,?,?,?)');
    foreach($files as$file){$s->bind_param('iisssis',$id,$event,$file['stored_name'],$file['display_name'],$file['mime'],$file['byte_size'],$file['content_hash']);$s->execute();}
}
