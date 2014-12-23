<?php

namespace Drupal\quizz_multichoice;

use Drupal\quizz_question\Entity\Question;
use Drupal\quizz_question\ResponseHandler;

class MultichoiceResponse extends ResponseHandler {

  protected $user_answer_ids;
  protected $choice_order;

  public function __construct($result_id, Question $question, $input = NULL) {
    parent::__construct($result_id, $question, $input['user_answer']);

    $this->user_answer_ids = array();

    // tries is the tries part of the post data
    if (isset($input['user_answer'])) {
      if (!is_array($input['user_answer'])) {
        // Account for single-select
        $input['user_answer'] = array($input['user_answer']);
      }
      if (isset($input['choice_order'])) {
        $this->choice_order = $input['choice_order'];
      }
      unset($input['choice_order']);
      if (isset($input['user_answer']) && is_array($input['user_answer'])) {
        foreach ($input['user_answer'] as $answer_id) {
          $this->user_answer_ids[] = $answer_id;
          $this->answer = $this->user_answer_ids; // @todo: Stop using user_answer_ids and only use answer instead…
        }
      }
      elseif (isset($input['user_answer'])) {
        $this->user_answer_ids[] = $input['user_answer'];
      }
    }
    else { // We load the answer from the database
      $input_ids = db_query(
        'SELECT answer_id FROM {quiz_multichoice_user_answers} ua
         LEFT OUTER JOIN {quiz_multichoice_user_answer_multi} uam ON(uam.user_answer_id = ua.id)
         WHERE ua.result_id = :result_id AND ua.question_vid = :question_vid', array(
          ':result_id'    => $result_id,
          ':question_vid' => $this->question->vid))
        ->fetchCol();
      if ($input_ids) {
        $this->user_answer_ids = $input_ids;
      }
    }
  }

  /**
   * Implementation of save
   *
   * @see QuizQuestionResponse#save()
   */
  public function save() {
    $user_answer_id = db_insert('quiz_multichoice_user_answers')
      ->fields(array(
          'result_id'    => $this->result_id,
          'question_vid' => $this->question->vid,
          'question_qid' => $this->question->qid,
          'choice_order' => $this->choice_order
      ))
      ->execute();

    $query = db_insert('quiz_multichoice_user_answer_multi')
      ->fields(array('user_answer_id', 'answer_id'));
    for ($i = 0; $i < count($this->user_answer_ids); $i++) {
      if ($this->user_answer_ids[$i]) {
        $query->values(array($user_answer_id, $this->user_answer_ids[$i]));
      }
    }
    $query->execute();
  }

  /**
   * Implementation of delete
   *
   * @see QuizQuestionResponse#delete()
   */
  public function delete() {
    $user_answer_id = array();
    $query = db_query('SELECT id FROM {quiz_multichoice_user_answers} WHERE question_qid = :qid AND question_vid = :vid AND result_id = :result_id', array(':qid' => $this->question->qid, ':vid' => $this->question->vid, ':result_id' => $this->result_id));
    while ($user_answer = $query->fetch()) {
      $user_answer_id[] = $user_answer->id;
    }

    if (!empty($user_answer_id)) {
      db_delete('quiz_multichoice_user_answer_multi')
        ->condition('user_answer_id', $user_answer_id, 'IN')
        ->execute();
    }

    db_delete('quiz_multichoice_user_answers')
      ->condition('result_id', $this->result_id)
      ->condition('question_qid', $this->question->qid)
      ->condition('question_vid', $this->question->vid)
      ->execute();
  }

  /**
   * Implementation of score
   *
   * @return uint
   *
   * @see QuizQuestionResponse#score()
   */
  public function score() {
    if ($this->question->choice_boolean || $this->isAllWrong()) {
      $score = $this->getQuestionMaxScore();
      foreach ($this->question->alternatives as $key => $alt) {
        if (in_array($alt['id'], $this->user_answer_ids)) {
          if ($alt['score_if_chosen'] <= $alt['score_if_not_chosen']) {
            $score = 0;
          }
        }
        elseif ($alt['score_if_chosen'] > $alt['score_if_not_chosen']) {
          $score = 0;
        }
      }
    }
    else {
      $score = 0;
      foreach ($this->question->alternatives as $key => $alt) {
        if (in_array($alt['id'], $this->user_answer_ids)) {
          $score += $alt['score_if_chosen'];
        }
        else {
          $score += $alt['score_if_not_chosen'];
        }
      }
    }
    return $score;
  }

  /**
   * If all answers in a question is wrong
   *
   * @return boolean
   *  TRUE if all answers are wrong. False otherwise.
   */
  public function isAllWrong() {
    foreach ($this->question->alternatives as $key => $alt) {
      if ($alt['score_if_chosen'] > 0 || $alt['score_if_not_chosen'] > 0) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Implementation of getResponse
   *
   * @return answer
   *
   * @see QuizQuestionResponse#getResponse()
   */
  public function getResponse() {
    return $this->user_answer_ids;
  }

  /**
   * Implementation of getFeedbackValues.
   */
  public function getFeedbackValues() {
    $this->orderAlternatives($this->question->alternatives);
    $simple_scoring = $this->question->choice_boolean;

    $data = array();
    foreach ($this->question->alternatives as $alternative) {
      $chosen = in_array($alternative['id'], $this->user_answer_ids);
      $not = $chosen ? '' : 'not_';

      $data[] = array(
          'choice'            => check_markup($alternative['answer']['value'], $alternative['answer']['format']),
          'attempt'           => $chosen ? quizz_icon('selected') : '',
          'correct'           => $chosen ? $alternative['score_if_chosen'] > 0 ? quizz_icon('correct') : quizz_icon('incorrect') : '',
          'score'             => $alternative["score_if_{$not}chosen"],
          'answer_feedback'   => check_markup($alternative["feedback_if_{$not}chosen"]['value'], $alternative["feedback_if_{$not}chosen"]['format'], FALSE),
          'question_feedback' => 'Question feedback',
          'solution'          => $alternative['score_if_chosen'] > 0 ? quizz_icon('should') : ($simple_scoring ? quizz_icon('should-not') : ''),
          'quiz_feedback'     => t('@quiz feedback', array('@quiz' => QUIZZ_NAME)),
      );
    }

    return $data;
  }

  /**
   * Order the alternatives according to the choice order stored in the database
   *
   * @param array $alternatives
   *  The alternatives to be ordered
   */
  protected function orderAlternatives(array &$alternatives) {
    if (!$this->question->choice_random) {
      return;
    }

    $result = db_query(
      'SELECT choice_order
          FROM {quiz_multichoice_user_answers}
          WHERE result_id = :result_id
            AND question_qid = :question_qid
            AND question_vid = :question_vid', array(
        ':result_id'    => $this->result_id,
        ':question_qid' => $this->question->qid,
        ':question_vid' => $this->question->vid))->fetchField();
    if (!$result) {
      return;
    }
    $order = explode(',', $result);
    $newAlternatives = array();
    foreach ($order as $value) {
      foreach ($alternatives as $alternative) {
        if ($alternative['id'] == $value) {
          $newAlternatives[] = $alternative;
          break;
        }
      }
    }
    $alternatives = $newAlternatives;
  }

}
