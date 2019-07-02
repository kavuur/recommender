<?php
	class Kavu {
		private $connection = null;
		private $user_id = 0;
		private $quer_queries = array();
		private $results = array();

		public function __construct($user_id){
			$this->user_id = $user_id;
		}

		public function set_connection($connection){
			if(!empty($connection)){
				$this->connection = $connection;
				return true;
			}
			return false;
		}

		public function set_queries(array $queries){
			if(!empty($queries)){
				$this->queries = $queries;
				return true;
			}
			return false;
		}

		public function get_results(){
			return empty($this->results)?false:$this->results;
		}

		public function run_algorithms(){
			if($this->user_id_exists($this->user_id)){
				if($this->user_id_old($this->user_id)){
					$similar_users = $this->get_similarly_acting_users($this->user_id);
					if(!empty($similar_users)){
						$most_similar_users = $this->get_most_similar_users($this->user_id, $similar_users);
						if(!empty($most_similar_users)){
							$this->extract_and_save_actioned_users($most_similar_users);
						} else {
							$this->extract_and_save_actioned_users($similar_users);
						}
					}
				} else {
					$similar_users = $this->get_similar_users($this->user_id);
					if(!empty($similar_users)){
						$most_similar_users = $this->get_most_similar_users($this->user_id, $similar_users);
						if(!empty($most_similar_users)){
							$this->extract_and_save_actioned_users($most_similar_users);
						} else {
							$this->extract_and_save_actioned_users($similar_users);
						}
					}
				}
			}

			return empty($this->results)?false:true;
		}
		
		//THIS public function RETURNS ID'S OF USERS SIMILAR TO THE SUBJECT USER
		private function get_similar_users($user_id){
			if(!empty($user_id) && $this->user_id_exists($user_id)){
				//GETTING ALL USERS LOGGED IN IN NOT MORE THAN A DAY AGO
				$data_statement = $this->connection->prepare($quer_queries[0]);
				$data_statement->execute([$user_id, time()]);
				if(isset($data_statement->errorInfo()[2]))exit("Database connection failure");
				else if($data_statement->rowCount()){
					$active_users = $data_statement->fetchAll(PDO::FETCH_NUM);
					if(!empty($active_users)){
						$similar_users = array();
						foreach($active_users AS $user){
							//FILTERING THE RETURNED USERS TO GET THOSE WITH COEFFICIENTS GREATER THAN 0.5
							if($this->get_similarity_coefficient($user_id, $user[0]) >= 0.5){
								array_push($similar_users, $user[0]);
							}
						}

						return $similar_users;
					}
				}
			}

			return false;
		}

		//THIS public function RETURNS ID'S OF USERS SIMILAR TO THE SUBJECT USER BASED ON THEIR PROFILE CLICKS
		private function get_similarly_acting_users($user_id){
			if(!empty($user_id) && $this->user_id_exists($user_id)){
				//GETTING THIS USER'S VIEWS
				$views = $this->get_user_views($user_id);

				if(!empty($views)){
					$questions = array();
					$arguments = array();
					array_push($arguments, $user_id);
					array_push($arguments, time());

					foreach($views AS $trash){
						array_push($questions, $quer_queries[1]);
						array_push($arguments, $trash);
					}

					if(!empty($questions) && !empty($arguments)){
						//GETTING ALL USERS LOGGED IN IN NOT MORE THAN A DAY AGO AND WITH CLICKS SIMILAR TO THE LOGGED IN USER
						$data_statement = $this->connection->prepare($quer_queries[2] . implode(" OR ", $questions) . ")");
						$data_statement->execute($arguments);
						if(isset($data_statement->errorInfo()[2]))exit("Database connection failure");
						else if($data_statement->rowCount()){
							$active_users = $data_statement->fetchAll(PDO::FETCH_NUM);
							if(!empty($active_users)){
								$similar_users = array();
								foreach($active_users AS $user){
									//FILTERING THE RETURNED USERS TO GET THOSE WITH COEFFICIENTS GREATER THAN 0.5
									if($this->get_similarity_coefficient($user_id, $user[0]) >= 0.5){
										array_push($similar_users, $user[0]);
									}
								}

								return $similar_users;
							}
						}
					}
				}
			}

			return false;
		}

		//THIS public function RETURNS ID'S OF USERS MOST SIMILAR TO THE SUBJECT USER BASED ON THE PROFILES THEY CLICKED
		private function get_most_similar_users($user_id, $similar_users){
			if(!empty($user_id) && $this->user_id_exists($user_id)){
				//PREPARING TO RETRIEVE PROFILES SIMILAR USERS VIEWED
				$data_query = $quer_queries[3];
				$questions = array();
				$arguments = array();
				foreach($similar_users AS $user){
					if($this->user_id_exists($user)){
						array_push($questions, $data_query);
						array_push($arguments, $user);
					} 
				}

				//RETRIEVING SIMILAR USERS
				if(!empty($questions) && !empty($arguments)){
					$data_statement = $this->connection->prepare(implode(' UNION ', $questions));
					$data_statement->execute($arguments);
					if(isset($data_statement->errorInfo()[2]))exit("Database connection failure");
					else if($data_statement->rowCount()){

						$viewers = array();
						$viewees = array();
						$viewer_viewee_array = array();

						foreach($data_statement AS $instance){
							array_push($viewers, $instance[0]);
							array_push($viewees, $instance[1]);
							array_push($viewer_viewee_array, [$instance[0], $instance[1]]);
						}

						if(!empty($viewer_viewee_array)){
							$common_views = array_count_values($viewees);
							if(!empty($common_views)){
								$most_similar_users = array();
								foreach($common_views AS $user_id => $frequency){
									if($frequency > 1){
										foreach($viewer_viewee_array AS $viewer_viewee){
											if($user_id == $viewer_viewee[1]){
												if(!in_array($user_id, $most_similar_users)){
													array_push($most_similar_users, $user_id);
												}
											}
										}
									}
								}
								if(!empty($most_similar_users)) return $most_similar_users;
							}
						}
					}
				}
			}

			return false;
		}

		//THIS public function USES THE JACCARD SIMILARITY ALGORITHM IS FOR GETTING THE SIMILARITY COEFFICIENT OF 2 USERS
		//http://www.code10.info/index.php%3Foption%3Dcom_content%26view%3Darticle%26id%3D60:article_jaccard-similarity%26catid%3D38:cat_coding_algorithms_data-similarity%26Itemid%3D57
		private function get_similarity_coefficient($user_1_id, $user_2_id) {
			if(!empty($user_1_id) && $this->user_id_exists($user_1_id) && !empty($user_2_id) && $this->user_id_exists($user_2_id)){
				//PREPARING TO RETRIEVE SENT USERS' INFORMATION
				$data_statement = $this->connection->prepare($quer_queries[4]);
				$data_statement->execute([$user_1_id]);
				if(isset($data_statement->errorInfo()[2])) exit("Database connection failure");
				else if($data_statement->rowCount() == 1){
					$user_1_details = $data_statement->fetch(PDO::FETCH_NUM);
					if(!empty($user_1_details)){
						$data_statement->execute([$user_2_id]);
						if(isset($data_statement->errorInfo()[2])) exit("Database connection failure");
						else if($data_statement->rowCount() == 1){
							$user_2_details = $data_statement->fetch(PDO::FETCH_NUM);
							if(!empty($user_2_details)){
								$intersection = array();
								$union = array();

								for($i = 0; $i<50; $i++){
									if($user_1_details[$i] == $user_2_details[$i]){
										array_push($union, $user_1_details[$i]);
										array_push($intersection, $user_1_details[$i]);
									} else {
										array_push($union, $user_1_details[$i]);
										array_push($union, $user_2_details[$i]);
									}
								}

								if(!empty($union) && count($union)){
									return (count($intersection) / count($union));
								}
							}
						}
					}
				}
			}

			return 0;
		}

		private function user_id_exists($user_id){
			if(!empty($user_id)){
				$user_id_statement = $this->connection->prepare($quer_queries[5]);
				$user_id_statement->execute([$this->filter($user_id)]);

				if(isset($user_id_statement->errorInfo()[2])){
					exit("<center>Database connection failure</center>");
				} if($user_id_statement->rowCount() == 1){
					return true;
				}
			}

			return false;
		}

		private function user_id_old($user_id){
			if(!empty($user_id)){
				$test_statement = $this->connection->prepare($quer_queries[6]);
				$test_statement->execute([$this->filter($user_id)]);

				if(isset($test_statement->errorInfo()[2])){
					exit("<center>Database connection failure</center>");
				} else {
					return $test_statement->fetchColumn();
				}
			}

			return false;
		}

		private function extract_and_save_actioned_users($user_ids){
			if(!empty($user_ids)){
				//LAST INTEGRITY CHECKUPS
				$questions = array();
				$arguments = array();
				array_push($arguments, $this->user_id);
				array_push($arguments, $this->user_id);

				foreach($user_ids AS $user_id){
					if($this->user_id_exists($user_id) && $this->user_id != $user_id){
						array_push($questions, $quer_queries[7]);
						array_push($arguments, $user_id);
					}
				}

				if(!empty($arguments) && !empty($questions)){
					$data_statement = $this->connection->prepare($quer_queries[8]. implode(" OR ", $questions) .$quer_queries[9]);
					$data_statement->execute($arguments);
					if(isset($data_statement->errorInfo()[2]))exit("Database connection failure");
					else {
						$views = $this->get_user_views($this->user_id);
						if(!empty($views)){
							foreach($data_statement AS $data){
								if(!in_array($data['id'], $views)){
									array_push($this->results, ["username" => $data['username'], "country_code" => get_country_code($data['country_id']), "age" => get_age($data['date_of_birth']), "country_name" => get_country_name($data['country_id']), "city_name" => get_city_name($data['city_id']) , "sex" => $data['sex']=='1'?"male":"female", "profile_photo" => get_photo($data['id'])]);
								}
							}
						} else {
							foreach($data_statement AS $data){
								array_push($this->results, ["username" => $data['username'], "country_code" => get_country_code($data['country_id']), "age" => get_age($data['date_of_birth']), "country_name" => get_country_name($data['country_id']), "city_name" => get_city_name($data['city_id']) , "sex" => $data['sex']=='1'?"male":"female", "profile_photo" => get_photo($data['id'])]);
								}
							}
					}
							
					return empty($this->results)?false:true;
				}
			}

			return false;
		}

		private function get_user_views($user_id){
			if(!empty($user_id) && $this->user_id_exists($user_id)){
				$data_statement = $this->connection->prepare($quer_queries[10]);
				$data_statement->execute([$user_id]);
				if(isset($data_statement->errorInfo()[2]))exit("Database connection failure");
				else if($data_statement->rowCount()){
					$views = array();
					foreach($data_statement AS $view){
						$views[] = $view['viewee'];
					}
					if(!empty($views)) return $views;
				}
			}
			return false;
		}

		private function filter($sent_data, $size = 0){
			$trimmed = trim(strip_tags($sent_data));
			if(!empty($trimmed)) return $size?substr($trimmed, 0, $size):$trimmed;
			else return $trimmed;
		}
	}
?>