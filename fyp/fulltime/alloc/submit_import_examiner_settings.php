<?php 
require_once('../../../Connections/db_ntu.php'); 
require_once('../../../CSRFProtection.php');
//require_once ('../../../PHPExcel/IOFactory.php'); 
require_once ('../../../vendor/autoload.php');

ini_set('max_execution_time', 600);

$redirect = false;
$error_code = 0; // no error

$csrf = new CSRFProtection();

$_REQUEST['validate'] = $csrf->cfmRequest();


// initialise variables for file upload
$target_dir 		= "../../../uploaded_files/";
$target_file 		= ""; 
$inputFileType 		= "";
$inputFileName 		= "";


if(isset($_FILES["FileToUpload_ExaminerSettings"])){

 	// Count total files
	$Countfiles 		= count($_FILES["FileToUpload_ExaminerSettings"]["name"]);
	$CountFilesMoved	= 0;
 	// Looping all files and move to target dir
	for($i=0;$i<$Countfiles;$i++){
		$target_file 	= $target_dir.basename($_FILES["FileToUpload_ExaminerSettings"]["name"][$i]); 
		$inputFileType 	= pathinfo($target_file,PATHINFO_EXTENSION);
		$inputFileName 	= $_FILES["FileToUpload_ExaminerSettings"]["name"][$i];
		// echo $target_file;
		if($inputFileType == "xlsx" || $inputFileType == "xls" || $inputFileType == "csv" ){
			if(move_uploaded_file($_FILES["FileToUpload_ExaminerSettings"]["tmp_name"][$i], $target_file)){
				// do nothing
			}else{
				$error_code=3;	// File is open
			}
		}else{
			$error_code=2; // Invalid file type
		}
	}
} else{
	$error_code=1; // Cannot find uploaded file
}

// Assign faculty workload into DB first
$FilesInDir = glob("$target_dir". "workload_staff_list.*");
if (count($FilesInDir) == 1){
	$error_code = HandleExcelData_WorkloadList($error_code, $FilesInDir[0]);
	if($error_code == 0 ){ // no error
		// Assign examinable faculty into DB first
		$FilesInDir = glob("$target_dir". "examinable_staff_list.*");
		if (count($FilesInDir) == 1){
			$error_code = HandleExcelData_ExaminableFacultyList($error_code, $FilesInDir[0]);
			if($error_code != 0){
				echo "Error in HandleExcelData_ExaminableFacultyList : error_code=$error_code\n";
			}
		}else{
			$error_code=4; // Cannot locate examinable_staff_list excel file
			echo "Cannot locate examinable_staff_list excel file\n";
		}
	}else{
		echo "Error in HandleExcelData_WorkloadList : error_code=$error_code\n";
	}
} else {
	$error_code=4; // Cannot locate workload_staff_list excel file
	echo "Cannot locate workload_staff_list excel file\n";
}



$redirect =true;
if (isset ($_REQUEST['validate'])) {
	
	echo "validate=1";
	
}		 
else if($redirect){
	echo ($error_code != 0) ? "error_code=$error_code" : "examiner_setting=1";
	
}
exit;




// CUSTOM CODE GOES HERE ---- do whatever you want

function HandleExcelData_WorkloadList($error_code, $InputFile_FullPath){
	$Contents = "********************************** LOADING WORKLOAD_STAFF_LIST **********************************\n";
	//$PHPExcelObj = PHPExcel_IOFactory::load($InputFile_FullPath);
	$PHPExcelObj = \PhpOffice\PhpSpreadsheet\IOFactory::load($InputFile_FullPath);
	$EXCEL_AllData = $PHPExcelObj->getActiveSheet()->toArray(null,true,true,true);

	global $TABLES, $conn_db_ntu; 
	try{
		$Offset						= 2; // Exclude headers
		$Total_DataInSheet 			= count($EXCEL_AllData) - $Offset;	
		$Total_DataEmpty			= 0;
		$AL_StaffWithoutEmail 	= array();
		$RowCount 					= 0;
		$RowCount_Updated			= 0;
		$RowCount_Created			= 0;




		// Data starts at row 3
		for ($RowIndex = 3; $RowIndex <=  $Total_DataInSheet + $Offset; $RowIndex ++) {
			$RowCount++;
			// Check if email is empty or null
			if(isset($EXCEL_AllData[$RowIndex]["B"]) && !empty($EXCEL_AllData[$RowIndex]["B"])){
				$EXCEL_StaffWorkLoad	= is_numeric ($EXCEL_AllData[$RowIndex]["N"]) && $EXCEL_AllData[$RowIndex]["N"] >= 0 ? $EXCEL_AllData[$RowIndex]["N"] : 0;
				$EXCEL_StaffEmail 		= strtolower($EXCEL_AllData[$RowIndex]["B"]);
				$EXCEL_StaffName 		= $EXCEL_AllData[$RowIndex]["C"];
				$EXCEL_StaffID			= explode('@', $EXCEL_StaffEmail)[0];
				// Check if the staff in excel list is in staff table
				$Stmt 			= sprintf("SELECT * FROM  %s WHERE id = '%s'", $TABLES["staff"], $EXCEL_StaffID);
				$DBOBJ_Result 	= $conn_db_ntu->prepare($Stmt);
				$DBOBJ_Result->execute();
				$Data 			= $DBOBJ_Result->fetch(PDO::FETCH_ASSOC);
				if(isset($Data['id']) && !empty($Data['id'])){
					// Try to update the workload of the staff
					$Stmt 			= sprintf("UPDATE %s SET WORKLOAD = %d WHERE id = '%s'", $TABLES["staff"], $EXCEL_StaffWorkLoad, $EXCEL_StaffID);
					$DBOBJ_Result 	= $conn_db_ntu->prepare($Stmt);
					if($DBOBJ_Result->execute()){
						$RowCount_Updated++;
						$Contents 	= $Contents . sprintf("%03d. Staff : %-25s : %-35s . Workload updated successfully \n", $RowCount, $Data['id'], $Data['name']);
					}else{
						$Contents 	= $Contents . sprintf("%03d. Staff : %-25s : %-35s . Workload was not updated successfully \n", $RowCount, $Data['id'], $Data['name']);
					}
					
				}else{
					// Try to create the workload of the staff
					$Stmt 			= sprintf("INSERT INTO %s (id, email, name, workload, examine) VALUES('%s', '%s', '%s', %d, %d)", $TABLES["staff"], $EXCEL_StaffID, $EXCEL_StaffEmail, $EXCEL_StaffName, $EXCEL_StaffWorkLoad, 0);
					$DBOBJ_Result 	= $conn_db_ntu->prepare($Stmt);
					if($DBOBJ_Result->execute()){
						$RowCount_Created++;
						$Contents 	= $Contents . sprintf("%03d. Staff : %-25s : %-35s . Workload created successfully! \n", $RowCount, $EXCEL_StaffID, $EXCEL_StaffName);
					}else{
						$Contents 	= $Contents . sprintf("%03d. Staff : %-25s : %-35s . Workload was not created successfully \n", $RowCount, $EXCEL_StaffID, $EXCEL_StaffName);
					}
				}
			}else{
				$Total_DataEmpty++;
				$EXCEL_StaffName 		= $EXCEL_AllData[$RowIndex]["C"];
				$AL_StaffWithoutEmail[$EXCEL_StaffName] = $EXCEL_StaffName;
				$Contents 	= $Contents . sprintf("%03d. %-30s : %-35s %-35s\n", $RowCount, "Empty email detected at row ", $RowCount, ". FAILED - No Email");
			}
		}

		$Contents 	= $Contents . sprintf("%-35s : %04d/%04d\n", "Total staff workload updated", $RowCount_Updated, $Total_DataInSheet-$Total_DataEmpty);
		$Contents 	= $Contents . sprintf("%-35s : %04d\n", "Total staff workload created", $RowCount_Created);
		// RESULT
		$file = "submit_import_examiner_settings.txt";
		file_put_contents($file, $Contents, LOCK_EX);
		return $error_code;
	} catch(Exception $Ex){
		echo  $Ex->getMessage();
		return $error_code=5; // General exception
	}
}


function HandleExcelData_ExaminableFacultyList($error_code, $InputFile_FullPath){
	$Contents = "********************************** LOADING EXAMINABLE_STAFF_LIST **********************************\n";
	//$PHPExcelObj = PHPExcel_IOFactory::load($InputFile_FullPath);
	$PHPExcelObj = \PhpOffice\PhpSpreadsheet\IOFactory::load($InputFile_FullPath);
	$EXCEL_AllData = $PHPExcelObj->getActiveSheet()->toArray(null,true,true,true);      	

	global $TABLES, $conn_db_ntu; 
	try{

	    // initialize all staff examinable to be 0
        $initialize = sprintf("UPDATE %s SET EXAMINE = 0", $TABLES["staff"]);
        $conn_db_ntu->exec($initialize);

		$Offset						= 2; // Exclude headers
		$Total_DataInSheet 			= count($EXCEL_AllData) - $Offset;	
		$Total_DataEmpty			= 0;
		$AL_StaffWithoutEmail 		= array();
		$AL_StaffNotInWorkLoadDB	= array();
		$RowCount 					= 0;
		$RowCount_Updated			= 0;
		$RowCount_Created			= 0;


		$sem = $_REQUEST['filter_Sem'];
		$year = $_REQUEST['filter_Year'];

		// Data starts at row 3
		for ($RowIndex = 3; $RowIndex <=  $Total_DataInSheet + $Offset; $RowIndex ++) {
			$RowCount++;

			if(isset($EXCEL_AllData[$RowIndex]["B"]) && !empty($EXCEL_AllData[$RowIndex]["B"])) {
                $EXCEL_StaffEmail = strtolower($EXCEL_AllData[$RowIndex]["B"]);
                $EXCEL_StaffName = $EXCEL_AllData[$RowIndex]["C"];
                $EXCEL_StaffID = explode("@", $EXCEL_StaffEmail)[0];
                $EXCEL_Loading = (explode("%", $EXCEL_AllData[$RowIndex]["E"])[0]) / 100;

                // Check if the staff in excel list is in staff table
                $Stmt = sprintf("SELECT * FROM  %s WHERE id = '%s'", $TABLES["staff"], $EXCEL_StaffID);
                $DBOBJ_Result = $conn_db_ntu->prepare($Stmt);
                $DBOBJ_Result->execute();
                $Data = $DBOBJ_Result->fetch(PDO::FETCH_ASSOC);

                // count the number of projects in the selected year and sem
                $stmt2 = sprintf("SELECT COUNT(*) FROM  %s WHERE acad_year = '%s' AND sem = '%s'", $TABLES["fyp"], $year, $sem);
                $DBOBJ_Result = $conn_db_ntu->prepare($stmt2);
                $DBOBJ_Result->execute();
                $projects = $DBOBJ_Result->fetchColumn();
                // only updates if there is project for the selected year and semester.
                if ($sem == 1 && $projects != 0) {
                    $facultySize = $Total_DataInSheet;
                    //echo '<script> alert("$Total_DataInSheet")</script>';
                    $base = $projects * 4 / $facultySize;
                    $exemption = int($base * (1 - $EXCEL_Loading));

                    if (isset($Data['id']) && !empty($Data['id'])) {
                        // Try to update the examine of the staff

                        $Stmt = sprintf("UPDATE %s SET exemption = %d, examine = %d WHERE id = '%s'", $TABLES["staff"], $exemption, 1, $EXCEL_StaffID);
                        $DBOBJ_Result = $conn_db_ntu->prepare($Stmt);
                        if ($DBOBJ_Result->execute()) {
                            $RowCount_Updated++;
                            $Contents = $Contents . sprintf("%03d. Staff : %-25s : %-35s . Examine updated successfully \n", $RowCount, $Data['id'], $Data['name']);
                        } else {
                            $Contents = $Contents . sprintf("%03d. Staff : %-25s : %-35s . Examine was not updated successfully \n", $RowCount, $Data['id'], $Data['name']);
                        }
                    } else {
                        // Try to create the Examine of the staff
                        $Stmt = sprintf("INSERT INTO %s (id, email, name, workload,exemption, examine) VALUES('%s', '%s', '%s', %d, %d, %d)", $TABLES["staff"], $EXCEL_StaffID, $EXCEL_StaffEmail, $EXCEL_StaffName, 0, $exemption, 1);
                        $DBOBJ_Result = $conn_db_ntu->prepare($Stmt);
                        if ($DBOBJ_Result->execute()) {
                            $RowCount_Created++;
                            $Contents = $Contents . sprintf("%03d. Staff : %-25s : %-35s : Exemption : %2d . Examine created successfully! \n", $RowCount, $EXCEL_StaffID, $EXCEL_StaffName, $exemption);
                        } else {
                            $Contents = $Contents . sprintf("%03d. Staff : %-25s : %-35s . Examine was not created successfully \n", $RowCount, $EXCEL_StaffID, $EXCEL_StaffName);
                        }
                    }
                }
                elseif ($sem == 2) {

                }

			} else{
				$Total_DataEmpty++;
				$EXCEL_StaffName 	= $EXCEL_AllData[$RowIndex]["C"];
				$AL_StaffWithoutEmail[$EXCEL_StaffName] = $EXCEL_StaffName;
				$Contents 	= $Contents . sprintf("%03d. %-30s : %-35s %-35s\n", $RowCount, "Empty email detected at row ", $RowCount, ". FAILED - No Email");
			}
		}

		// $Stmt = sprintf("SELECT COUNT(*) FROM %s WHERE examine=1;", $TABLES["staff_workload"]);
		// $DBOBJ_Result = $conn_db_ntu->prepare($Stmt);
		// $DBOBJ_Result->execute();
		// $RowCount = $DBOBJ_Result->fetchColumn();

		$Contents 	= $Contents . sprintf("%-35s : %04d/%04d\n", "Total staff examine updated", $RowCount_Updated, $Total_DataInSheet-$Total_DataEmpty);
		$Contents 	= $Contents . sprintf("%-35s : %04d\n", "Total staff examine created", $RowCount_Created);
		// RESULT
		$file = "submit_import_examiner_settings.txt";
		file_put_contents($file, $Contents, FILE_APPEND | LOCK_EX);
		return $error_code;
	} catch(Exception $Ex){
		echo  $Ex->getMessage();
		return $error_code=5; // General exception
	}
}


?>