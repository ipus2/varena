<?php

class Problem extends BaseObject {
  const MIN_TESTS = 1;
  const MAX_TESTS = 100;

  const VIS_PRIVATE = 0;
  const VIS_PUBLIC = 1;

  private static $VIS_NAMES = null;

  private $user = null;
  private $html = null;

  static function init() {
    self::$VIS_NAMES = [
      self::VIS_PRIVATE => _('private'),
      self::VIS_PUBLIC => _('public'),
    ];
  }

  static function getVisibilities() {
    return self::$VIS_NAMES;
  }

  function getUser() {
    if (!$this->user) {
      $this->user = User::get_by_id($this->userId);
    }
    return $this->user;
  }

  function getHtml() {
    if ($this->html === null) {
      $this->html = StringUtil::textile($this->statement);
    }
    return $this->html;
  }

  function getTags() {
    return Model::factory('Tag')
      ->table_alias('t')
      ->select('t.*')
      ->join('problem_tag', ['t.id', '=', 'pt.tagId'], 'pt')
      ->where('pt.problemId', $this->id)
      ->order_by_asc('pt.rank')
      ->find_many();
  }

  function getAttachmentDir() {
    return sprintf("%s/uploads/attachments/%s",
                   Util::$rootPath,
                   $this->name);
  }

  /* Returns the attachment corresponding to the input file for test case $num. */
  function getTestInput($num) {
    return Attachment::get_by_problemId_name(
      $this->id,
      sprintf(Attachment::PATTERN_TEST_IN, $num)
    );
  }

  /* Returns the attachment corresponding to the witness file for test case $num. */
  function getTestWitness($num) {
    return Attachment::get_by_problemId_name(
      $this->id,
      sprintf(Attachment::PATTERN_TEST_OK, $num)
    );
  }

  /* Returns the attachment corresponding to the grader. */
  function getGrader() {
    return Attachment::get_by_problemId_name(
      $this->id,
      sprintf(Attachment::PATTERN_GRADER, $this->grader)
    );
  }

  /**
   * Returns an array of [first, last] pairs. Throws an exception if
   * testGroups is inconsistent.
   **/
  function getTestGroups() {
    $result = [];

    if (!$this->testGroups) {
      for ($i = 1; $i <= $this->numTests; $i++) {
        $result[] = ['first' => $i, 'last' => $i];
      }
    } else {
      $prev = 0;
      $groups = explode(';', $this->testGroups);

      foreach ($groups as $i => $g) {
        $parts = explode('-', $g);
        if (count($parts) == 1) {
          $first = $last = $parts[0]; // single test case
        } else if (count($parts) == 2) {
          list($first, $last) = $parts;
        } else {
          throw new Exception(sprintf(_('Too many dashes in group %d.'), $i + 1));
        }

        if (!ctype_digit($first) || !ctype_digit($last)) {
          throw new Exception(sprintf(_('Illegal character in group %d.'), $i + 1));
        }

        if ($first > $last) {
          throw new Exception(sprintf(_('Wrong order in group %d.'), $i + 1));
        }

        if ($first != $prev + 1) {
          throw new Exception(sprintf(_('Group %d should start at test %d.'),
                                      $i + 1, $prev + 1));
        }

        if ($last > $this->numTests) {
          throw new Exception(sprintf(_('Value exceeds number of tests in group %d.'), $i + 1));
        }

        $result[] = ['first' => $first, 'last' => $last];
        $prev = $last;
      }

      if ($prev != $this->numTests) {
        throw new Exception(sprintf(_('Tests %d through %d are missing.'),
                                    $prev + 1, $this->numTests));
      }
    }

    return $result;
  }

  /**
   * Returns an array of test number => points.
   * Boring for now, but in the future some tests may be worth more than others.
   **/
  function getTestPoints() {
    return array_fill(1, $this->numTests, 100 / $this->numTests);
  }
  
  /**
   * Validates a problem for correctness. Returns an array of { field => array of errors }.
   **/
  function validate() {
    $errors = [];

    if (!preg_match('/^[a-z0-9]{2,15}$/', $this->name)) {
      $errors['name'] = _('The problem name must be between 2 and 15 symbols long and contain lowercase letters and digits only.');
    }

    $other = Model::factory('Problem')
           ->where('name', $this->name)
           ->where_not_equal('id', (int) $this->id) // could be "" when adding a new problem
           ->find_one();
    if ($other) {
      $errors['name'] = _('There already exists a problem with this name.');
    }

    if (!$this->statement) {
      $errors['statement'] = _('The statement cannot be empty.');
    }

    if ($this->numTests < self::MIN_TESTS ||
        $this->numTests > self::MAX_TESTS) {
      $errors['numTests'] = sprintf(_('Problems must have between %d and %d tests.'),
                                    self::MIN_TESTS,
                                    self::MAX_TESTS);
    }

    if ($this->timeLimit <= 0) {
      $errors['timeLimit'] = _('The time limit must be positive.');
    }

    if ($this->memoryLimit <= 0) {
      $errors['memoryLimit'] = _('The memory limit must be positive.');
    }

    if (!$this->grader && !$this->hasWitness) {
      $errors['grader'] = _('Problems must either use a grader or .ok files (or both).');
    }

    if ($this->publicSources!=0 && $this->publicSources!=1) {
      $errors['publicSources'] = _('Public sources field has to be true or false.');
    }

    if ($this->publicTests!=0 && $this->publicTests!=1) {
      $errors['publicTests']= _ ('Public tests field has to be true or false.');
    }

    if ($this->year<1970 || $this->year>date("Y")) {
      $errors['year']= _ ('Year is invalid.');
    }

    if ($this->grade!='juniors' && $this->grade!='seniors') {
      $arr = str_split($this->grade);
      $i = 0;
      $gradeInt = 0;

      while ($i<count($arr) && $arr[$i]>='0' && $arr[$i]<='9') {
        $gradeInt = $gradeInt*10 + $arr[$i] - '0';
        $i++;
      }
      
      if ($i!=count($arr) || $gradeInt<5 || $gradeInt>12) {
        $errors['grade'] = _('Grade must range from 5 to 12, or be either juniors or seniors');
      }
    }

    try {
      $this->getTestGroups();
    } catch (Exception $e) {
      $errors['testGroups'] = $e->getMessage();
    }

    return $errors;
  }

  /**
   * Current policy:
   * * people with the proper permission can view it
   * * the author can view it.
   **/
  function viewableBy($user) {
    return
      ($this->visibility == self::VIS_PUBLIC) ||
      ($user && $user->can(Permission::PERM_EDIT_PROBLEM)) ||
      ($user && ($user->id == $this->userId));
  }

  /**
   * Current policy:
   * * anonymous users can not edit anything (duh);
   * * people with the proper permission can edit all problems;
   * * users can edit problems they created.
   **/
  function editableBy($user) {
    return $user &&
      ($user->can(Permission::PERM_EDIT_PROBLEM) ||
       ($user->id == $this->userId));
  }

  /**
   * Current policy: true
   **/
  function testsViewableBy($user) {
    return true;
  }

}

Problem::init();

?>
