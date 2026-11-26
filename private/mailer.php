<?php
// SMTP is opt-in; existing mail transport remains the default.
function onbMail(array $c,string $to,string $subject,string $body):bool {
 if(!filter_var($to,FILTER_VALIDATE_EMAIL)||!filter_var($c['mail_from']??'',FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$subject))return false;
 $from=$c['mail_from'];
 $html='<html><body style="background:#f5f3ed;font-family:Arial;padding:24px"><div style="max-width:600px;margin:auto;background:white;padding:28px"><div style="background:#193e32;color:white;padding:24px;letter-spacing:3px">OUR NEXT BOARDING</div><h2 style="color:#193e32">'.htmlspecialchars($subject,ENT_QUOTES,'UTF-8').'</h2><p style="line-height:1.7">'.nl2br(htmlspecialchars($body,ENT_QUOTES,'UTF-8')).'</p><hr><small>Extraordinary Destinations. Unforgettable Journeys.</small></div></body></html>';
 if(empty($c['smtp_enabled']))return mail($to,$subject,$html,"From: Our Next Boarding <".$from.">\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8",'-f'.$from);
 try {
 require_once __DIR__.'/../vendor/autoload.php';
 $m=new \PHPMailer\PHPMailer\PHPMailer(true);
 $m->isSMTP();$m->Host=$c['smtp_host'];$m->Port=(int)($c['smtp_port']??465);$m->SMTPAuth=true;$m->Username=$c['smtp_user'];$m->Password=$c['smtp_password'];$m->SMTPSecure=$m->Port===465?'ssl':'tls';$m->Timeout=15;$m->CharSet='UTF-8';$m->setFrom($from,'Our Next Boarding');$m->addAddress($to);$m->Subject=$subject;$m->isHTML(true);$m->Body=$html;$m->AltBody=$body;return $m->send();
 }catch(Throwable $e){error_log('Our Next Boarding SMTP submission failed');return false;}
}
