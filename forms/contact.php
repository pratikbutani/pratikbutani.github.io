<?php
  /**
  * Requires the "PHP Email Form" library
  * The "PHP Email Form" library is available only in the pro version of the template
  * The library should be uploaded to: vendor/php-email-form/php-email-form.php
  * For more info and help: https://bootstrapmade.com/php-email-form/
  */

  // Replace with your real receiving email address
  $receiving_email_address = 'pratik13butani@gmail.com';

  if( file_exists($php_email_form = '../assets/vendor/php-email-form/php-email-form.php' )) {
    include( $php_email_form );

    $contact = new PHP_Email_Form;
    $contact->ajax = true;

    $contact->to = $receiving_email_address;
    $contact->from_name = $_POST['name'];
    $contact->from_email = $_POST['email'];
    $contact->subject = $_POST['subject'];

    $contact->add_message( $_POST['name'], 'From');
    $contact->add_message( $_POST['email'], 'Email');
    $contact->add_message( $_POST['message'], 'Message', 10);

    echo $contact->send();
  } else {
    // Fallback to PHP mail() function if the library is not present
    $name = $_POST['name'];
    $email = $_POST['email'];
    $subject = $_POST['subject'];
    $message = $_POST['message'];

    $to = $receiving_email_address;
    $headers = "From: $name <$email>" . "\r\n" .
               "Reply-To: $email" . "\r\n" .
               "X-Mailer: PHP/" . phpversion();

    $email_content = "Name: $name\n";
    $email_content .= "Email: $email\n\n";
    $email_content .= "Message:\n$message\n";

    if(mail($to, $subject, $email_content, $headers)) {
      echo 'OK';
    } else {
      echo 'Error: Unable to send email. Please check your server configuration.';
    }
  }
?>
