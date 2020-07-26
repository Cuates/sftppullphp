#!/usr/bin/php
<?php
  /*
          File: sftp_pull.php
       Created: 07/23/2020
       Updated: 07/25/2020
    Programmer: Cuates
    Updated By: Cuates
       Purpose: Retrieve files from SFTP server and send to network share drive for later processing
  */

  // Include error check class
  include ("checkerrorclass.php");

  // Create an object of error check class
  $checkerrorcl = new checkerrorclass();

  // Set variables
  $developerNotify = 'cuates@email.com'; // Production email(s)
  // $developerNotify = 'cuates@email.com'; // Development email(s)
  $endUserEmailNotify = 'cuates@email.com'; // Production email(s)
  // $endUserEmailNotify = 'cuates@email.com'; // Development email(s)
  $externalEndUserEmailNotify = ''; // Production email(s)
  // $externalEndUserEmailNotify = 'cuates@email.com'; // Development email(s)
  $scriptName = 'SFTP Pull'; // Production
  // $scriptName = 'TEST SFTP Pull TEST'; // Development
  $fromEmailServer = 'Email Server';
  $fromEmailNotifier = 'email@email.com';

  // Retrieve any other issues not retrieved by the set_error_handler try/catch
  // Parameters are function name, $email_to, $email_subject, $from_mail, $from_name, $replyto, $email_cc and $email_bcc
  register_shutdown_function(array($checkerrorcl,'shutdown_notify'), $developerNotify, $scriptName . ' Error', $fromEmailNotifier, $fromEmailServer, $fromEmailNotifier);

  // Function to catch exception errors
  set_error_handler(function ($errno, $errstr, $errfile, $errline)
  {
    throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
  });

  // Attempt script logic
  try
  {
    // // Set new memory limit Note: This will revert back to original limit upon end of script
    // ini_set('memory_limit', '4095M');

    // Declare download directory
    define ('TEMPDOC', '/var/www/html/Temp_Directory/');
    define ('RECEIVEDDOC', '/var/www/html/Doc_Directory/');
    // define ('MOUNTDRIVE', '/mnt/DEV/Pull/'); // Development
    define ('MOUNTDRIVE', '/mnt/PROD/Pull/'); // Production

    // Set local
    // setlocale(LC_ALL, "en_US.utf8");

    // Include class file
    include ("sftp_pull_class.php");

    // Create an object of class
    $sftp_pull_cl = new sftp_pull_class();

    // Initialize variables
    $errorPrefixFilename = "sftp_pull_issue_"; // Production
    // $errorPrefixFilename = "sftp_pull_dev_issue_"; // Development
    $errormessagearray = array();
    $extensionsException = array("csv");
    $dtsxPrefixFilename = "File_Name.csv";

    // Set the information to pull files from SFTP server
    $jobInformation = array(array("JobName" => "SFTP" , "Path" => RECEIVEDDOC, "Archive" => RECEIVEDDOC . "Archive/", "Error" => RECEIVEDDOC . "Error/", "Filename" => "retrieve_file_", "Email" => ""));

    // Process all in information the array
    foreach($jobInformation as $infoVal)
    {
      // Set parameters
      $jobName = reset($infoVal);
      $localPath = next($infoVal);
      $archivePath = next($infoVal);
      $errorPath = next($infoVal);
      $prefixFilename = next($infoVal);
      $emailList = next($infoVal);

      // Retrieve a list of information to process from the external server
      // List SFTP Files (job name, extension array to look for)
      $retrieveFileList = $sftp_pull_cl->listSFTPFiles($jobName, $extensionsException);

      // Check if server error
      if (!isset($retrieveFileList['SError']) && !array_key_exists('SError', $retrieveFileList))
      {
        // Join the list of file(s) from an array into an string
        $extImp = implode('|', $extensionsException);

        // Retrieve file(s)
        foreach($retrieveFileList as $fileNameInListVal)
        {
          // Check filename amongst all other files for proper processing
          if(preg_match('/^File_Name_[0-9]{14}\.(' . $extImp . ')$/i', $fileNameInListVal))
          {
            // Retrieve file for later process
            // Get SFTP file from server (filename in remote server, local path storage, filename on local server)
            $retrieveFile = $sftp_pull_cl->getSFTPFile($fileNameInListVal, $jobName, TEMPDOC, $fileNameInListVal);

            // Explode the message returned from the function
            $retrieveFileArray = explode('~', $retrieveFile);

            // Set response message
            $retrieveFileResp = reset($retrieveFileArray);
            $retrieveFileMesg = next($retrieveFileArray);

            // Check if the message was returned successfully
            if ($retrieveFileResp === "Success")
            {
              // Check file for information
              $filename_parts = pathinfo(TEMPDOC . $fileNameInListVal);

              // Save file name without extension
              $fileNameNoExt = $filename_parts['filename'];

              // Save file name extension
              $fileNameOrgExt = $filename_parts['extension'];

              // Replace anything that does not include upper and lower characters, numbers, dashes, underscores, and periods with an underscore character
              $fileNameNoExtStringFix = preg_replace('/[^a-zA-Z0-9\-_.]/', '_', $fileNameNoExt);

              // Set local file name for later process
              $filenameLocal = $prefixFilename . $fileNameNoExtStringFix . '_' . date("Y-m-d_H-i-s") . '.csv';

              // Check if the file is readable
              if (($handle2CSV = fopen(TEMPDOC . $fileNameInListVal, "r")) !== FALSE)
              {
                // Check if the file is writeable
                if (($myCSVFile = fopen($localPath . $filenameLocal, "w")) !== FALSE)
                {
                  // Process all information within the file
                  while (($dataCSV = fgetcsv($handle2CSV, 1000, ",")) !== FALSE)
                  {
                    // CSV put into new file
                    fputcsv($myCSVFile, $dataCSV);
                  }

                  // Close file
                  fclose($myCSVFile);

                  // Check if file was written to local directory
                  if (file_exists($localPath . $filenameLocal))
                  {
                    // Save original file name
                    $orgFileName = $fileNameNoExt . '.' . $fileNameOrgExt;

                    // Move original file to archive folder and remove original file from in folder
                    $moveFile = $sftp_pull_cl->moveSFTPFile($orgFileName, 'SFTP', 'In_Directory/', 'Archive_Directory/', TEMPDOC);

                    // Explode database message
                    $moveFileArray = explode('~', $moveFile);

                    // Set response message
                    $moveFileResp = reset($moveFileArray);
                    $moveFileMesg = next($moveFileArray);

                    // Check if an error message was returned
                    if ($moveFileResp !== "Success")
                    {
                      // Append message
                      array_push($errormessagearray, array('Move Remote File', $jobName, '', '', '', '', '', '', $orgFileName, 'Error', $moveFileMesg));
                    }
                  }
                  else
                  {
                    // File was not written to local directory
                    // Append message
                    array_push($errormessagearray, array('Convert Download File To CSV', $jobName, '', '', '', '', '', '', $localPath . $filenameLocal, 'Error', 'Converted file not written to server'));
                  }
                }
                else
                {
                  // Else file was not able to write
                  // Append message
                  array_push($errormessagearray, array('File Write', $jobName, '', '', '', '', '', '', $localPath . $filenameLocal, 'Error', 'Unable to write ' . $filenameLocal));
                }

                // Close file
                fclose($handle2CSV);
              }
              else
              {
                // Else file was not able to open
                // Append message
                array_push($errormessagearray, array('File Open', $jobName, '', '', '', '', '', '', $localPath . $filenameLocal, 'Error', 'Unable to open ' . $fileNameInListVal));
              }

              // Check if file exist
              if (file_exists(TEMPDOC . $fileNameInListVal))
              {
                // Delete the unwanted file from the server
                unlink(TEMPDOC . $fileNameInListVal);
              }
            }
            else
            {
              // Append message
              array_push($errormessagearray, array('Download File', $jobName, '', '', '', '', '', '', $fileNameInListVal, 'Error', $retrieveFileMesg));
            }
          }
        }
      }
      else
      {
        // Set message
        $retrieveFileListMesg = reset($retrieveFileList);

        // Append error message
        array_push($errormessagearray, array('Retrieve File(s) List', $jobName, '', '', '', '', '', '', '', 'Error', $retrieveFileListMesg));
      }

      // Retrieve all files from the saved directory
      $filesToProcess = array_filter(glob($localPath . '*'), 'is_file');
      $extentionTypes = array('csv');

      // Check if there is anything to process
      if (count($filesToProcess) > 0)
      {
        // Define header column attribute names for the file being read from the server
        $headerValue = array(array('COLUMN_01', 'COLUMN_02', 'COLUMN_03', 'COLUMN_04', 'COLUMN_05', 'COLUMN_06'));

        // Create file handle
        $fp = fopen($localPath . $dtsxPrefixFilename, 'w');

        // Loop through array and write to file
        foreach ($headerValue as $valhd)
        {
          // Write to file
          fputcsv($fp, $valhd);
        }

        // Close file handle
        fclose($fp);

        // Process through all files
        foreach($filesToProcess as $valFile)
        {
          // Check if file exist before processing
          if (file_exists($valFile) && trim($valFile) !== "")
          {
            // Check file for information
            $filename_parts_local = pathinfo($valFile);

            // Save file name extension
            $fileNameExt = strtolower($filename_parts_local['extension']);

            // Check the mime type with what is in the acceptable mime types array
            if(in_array($fileNameExt, $extentionTypes))
            {
              // Initialize variables and arrays
              $dataArray = array();

              // Get the position of the file number headers from the file
              $fileNumberHeader = 0;

              // Create a column header name array
              $columnHeaderNameArray = array('column_01', 'column_02', 'column_03', 'column_04', 'column_05', 'column_06');

              // Create a column number array
              $columnNumberArray = array();

              // If we are able to open the file we want to access continue
              if (($handle = fopen($valFile, "r")) !== FALSE)
              {
                // Go through the first line of the csv file to retrieve the header(s) we want
                if (($data = fgetcsv($handle, ",")) !== FALSE)
                {
                  // Count the number of data that is in the file
                  $numHeader = count($data);

                  // Proceed with the first line which will usually be the column headers
                  for ($c = 0; $c < $numHeader; $c++)
                  {
                    // If the position in the data equals to anything that we are looking for in the first row of data
                    // Important note is to replace any unwanted characters from the string
                    if (in_array(strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $data[$c]))), $columnHeaderNameArray))
                    {
                      // Error log the data to see what is being compared in the array
                      // error_log('Data from header: ' . $data[$c]);

                      // If so then put the position number into the array for later data retrieval
                      $columnNumberArray[$fileNumberHeader] = trim($c);

                      // Increment to the next number to input any more data into the array
                      $fileNumberHeader++;
                    }
                  }
                }

                // Retrieve everything from the file if any
                while (($data = fgetcsv($handle, ",")) !== FALSE)
                {
                  // Count the number of data that is in the file
                  $num = count($data);

                  // position in array
                  $posInArray = 0;

                  // Temporary array to store current data from csv file
                  $rowData = array();

                  // Proceed with the next line which will usually be the data below the column headers
                  for ($d = 0; $d < $num; $d++)
                  {
                    // check to see if that value exist inside the array
                    if (in_array($d, $columnNumberArray))
                    {
                      // Check if string is in UTF8
                      // \! is the delimiter and u is the modifier that treats the pattern as utf8
                      if (preg_match('!!u', trim($data[$d])))
                      {
                        // this is utf-8
                        $rowData[$posInArray] = trim($data[$d]);
                      }
                      else
                      {
                        // definitely not utf-8
                        $rowData[$posInArray] = trim(utf8_encode($data[$d]));
                      }

                      // Increment to the next number to input any more data into the array
                      $posInArray++;
                    }
                  }

                  // Push row data into the overall file data array for later processing
                  array_push($dataArray, $rowData);
                }

                // Close the file after processing everything in the file
                fclose($handle);

                // Retrieve basename of the file
                $savedFileName = basename($valFile);

                // Check if file exist
                if (file_exists($localPath . $savedFileName))
                {
                  // Delete the unwanted file from the server
                  unlink($localPath . $savedFileName);
                }

                // Create file handle
                $fpda = fopen($localPath . $dtsxPrefixFilename, 'a+');

                // Loop through array and write to file
                foreach ($dataArray as $valrda)
                {
                  // Write to file
                  fputcsv($fpda, $valrda);
                }

                // Close file handle
                fclose($fpda);
              }
              else
              {
                // Store error message
                array_push($errormessagearray, array('Read Local File', $jobName, '', '', '', '', '', '', $valFile, 'Error', 'Unable to read file'));
              }
            }
          }
          else
          {
            // Store error message
            array_push($errormessagearray, array('Local File Not Found', $jobName, '', '', '', '', '', '', $valFile, 'Error', 'File Does not Exist in Directory'));
          }
        }

        // Check if file exist before moving to another directory
        if (file_exists($localPath . $dtsxPrefixFilename))
        {
          // Create file handle
          $fpfx = fopen($localPath . $dtsxPrefixFilename, 'a+');

          // Gets information about a file using an open file pointer
          $stat = fstat($fpfx);

          // Truncates a file to a given length
          $truncateReponse = ftruncate($fpfx, $stat['size'] - 1);

          // Close file handle
          fclose($fpfx);

          // Move CSV file to archive
          // Rename old file with new file and location
          $moveStatus = rename($localPath . $dtsxPrefixFilename, MOUNTDRIVE . $dtsxPrefixFilename);

          // Check if the rename function was not able to move the file to the archive directory
          if ($moveStatus !== TRUE)
          {
            // Set array with error records for processing
            array_push($errormessagearray, array('Move File to Archive', $jobName, '', '', '', '', '', '', $valFile, 'Error', 'There was an issue moving the file to mounted drive'));
          }
        }
      }
    }

    // Check if error message array is not empty
    if (count($errormessagearray) > 0)
    {
      // Set prefix file name and headers
      $errorFilename = $errorPrefixFilename . date("Y-m-d_H-i-s") . '.csv';
      $colHeaderArray = array(array('Process', 'Job Name', 'Column 01', 'Column 02', 'Column 03', 'Column 04', 'Column 05', 'Column 06', 'File Name', 'Response', 'Message'));

      // Initialize variable
      $to = "";
      $to = $developerNotify;
      $to_cc = "";
      $to_bcc = "";
      $fromEmail = $fromEmailNotifier;
      $fromName = $fromEmailServer;
      $replyTo = $fromEmailNotifier;
      $subject = $scriptName . " Error";

      // Set the email headers
      $headers = "From: " . $fromName . " <" . $fromEmail . ">" . "\r\n";
      // $headers .= "CC: " . $to_cc . "\r\n";
      // $headers .= "BCC: " . $to_bcc . "\r\n";
      $headers .= "MIME-Version: 1.0\r\n";
      $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
      // $headers .= "X-Priority: 3\r\n";

      // Mail priority levels
      // "X-Priority" (values: 1, 3, or 5 from highest[1], normal[3], lowest[5])
      // Set priority and importance levels
      $xPriority = "";

      // Set the email body message
      $message = "<!DOCtype html>
      <html>
        <head>
          <title>"
            . $scriptName .
            " Error
          </title>
          <meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
          <!-- Include next line to use the latest version of IE -->
          <meta http-equiv=\"X-UA-Compatible\" content=\"IE=Edge\" />
        </head>
        <body>
          <div style=\"text-align: center;\">
            <h2>"
              . $scriptName .
              " Error
            </h2>
          </div>
          <div style=\"text-align: center;\">
            There was an issue with " . $scriptName . " Error process.
            <br />
            <br />
            Do not reply, your intended recipient will not receive the message.
          </div>
        </body>
      </html>";

      // Call notify developer function
      $sftp_pull_cl->notifyDeveloper(TEMPDOC, $errorFilename, $colHeaderArray, $errormessagearray, $to, $to_cc, $to_bcc, $fromEmail, $fromName, $replyTo, $subject, $headers, $message, $xPriority);
    }
  }
  catch(Exception $e)
  {
    // Call to the function
    $checkerrorcl->caught_error_notify($e, $developerNotify, $scriptName . ' Error', $fromEmailNotifier, $fromEmailServer, $fromEmailNotifier);
  }
?>