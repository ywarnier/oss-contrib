<?php

namespace Contributions\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;

/**
 * Add command.
 */
class AddCommand extends Command {

    /**
     * Label for undefined person.
     *
     * @var string
     */
    const UNDEFINED_PERSON= 'New person';

    /**
     * Label for undefined project.
     *
     * @var string
     */
    const UNDEFINED_PROJECT = 'New project';

    /**
     * Parsed YAML structure.
     *
     * @var array
     */
    protected $contributions;

    /**
     * {@inheritdoc}
     */
    protected function configure() {
        $this
            ->setName('contributions:add')
            ->setDescription('Add a new contributions entry to the YAML file')
            ->addArgument(
                'contributions-yml',
                InputArgument::REQUIRED,
                'YAML file to use as input if exists, and that will be edited or generated.'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $yml_file = $input->getArgument('contributions-yml');
        if (!file_exists($yml_file)) {
            // TODO No initial YAML structure.
            throw new \InvalidArgumentException('Generating the YAML from scratch is not yet supported, use contributions.yml file as base to create one.');
        }
        // The extra flag allows dates to use \Datetime, which is useful when
        // serializing it back to keep the ISO 8601 format.
        $this->contributions = Yaml::parseFile($yml_file, Yaml::PARSE_DATETIME);
        $project = $this->getProject($input, $output);
        $contribution = $this->getContribution($project, $input, $output);
        $this->contributions['contributions'][] = $contribution;
        // Finalize and write out.
        $this->prepareContributions();
        $yaml = Yaml::dump($this->contributions, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        if (file_put_contents($yml_file, $yaml) == FALSE) {
            $output->writeln(sprintf('The file "%s" could not be updated correctly', $yml_file));
            return Command::FAILURE;
        }
        $output->writeln(sprintf('The file "%s" was updated correctly', $yml_file));
        return Command::SUCCESS;
    }

    /**
     * Helper to get related project.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *   Input object.
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *   Output object.
     *
     * @return string
     *   The project to use, e.g. drupal/migrate_plus.
     */
    protected function getProject(InputInterface $input, OutputInterface $output) {
        $helper = $this->getHelper('question');
        $projects = array_keys($this->contributions['projects']);
        array_unshift($projects, self::UNDEFINED_PROJECT);
        $question = new ChoiceQuestion(
            '[1/7] Which project received the contribution?',
            $projects,
            0
        );
        $question->setErrorMessage('Project %s is invalid.');
        $project = $helper->ask($input, $output, $question);
        if ($project == self::UNDEFINED_PROJECT) {
            $question = new Question('What is the machine name of the project? (E.g. drupal/migrate_plus): ');
            $question->setValidator([self::class, 'isNotEmpty']);
            $machine_name = $helper->ask($input, $output, $question);
            $question->setValidator([self::class, 'isNotEmpty']);
            $question = new Question('What is the name of the project? (E.g. Migrate Plus): ');
            $question->setValidator([self::class, 'isNotEmpty']);
            $name = $helper->ask($input, $output, $question);
            $question = new Question('What is the main URL of the project? (E.g. https://www.drupal.org/project/migrate_plus): ');
            $question->setValidator([self::class, 'isNotEmpty']);
            $url = $helper->ask($input, $output, $question);
            $this->contributions['projects'][$machine_name] = [
                'name' => $name,
                'url' => $url,
            ];
            $project = $machine_name;
        }
        return $project;
    }

    /**
     * Helper to get the contribution data.
     *
     * @param string $project
     *   The project to use, e.g. drupal/migrate_plus.
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *   Input object.
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *   Output object.
     *
     * @return array
     *   The contribution data.
     */
    protected function getContribution(string $project, InputInterface $input, OutputInterface $output) {
        $helper = $this->getHelper('question');
        $contribution = ['project' => $project];
        $question = new Question('[2/7] Please give the contribution a title: ');
        $question->setValidator([self::class, 'isNotEmpty']);
        $contribution['title'] = $helper->ask($input, $output, $question);
        $question = new ChoiceQuestion(
            '[3/7] What was the main type of the contribution? [default: code]',
            $this->contributions['types'],
            'code'
        );
        $question->setErrorMessage('Type %s is invalid.');
        $contribution['type'] = $helper->ask($input, $output, $question);
        $contribution['who'] = $this->getPerson($input, $output);
        $today = date('Y-m-d');
        $question = new Question("[5/7] When was the contribution first published? (E.g. 2020-01-22) [default: $today]: ", $today);
        $question->setValidator(function ($value) {
            if (empty($value) || strtotime($value) === FALSE) {
                throw new \RuntimeException('Invalid date. Please provide a time string like 2020-01-22.');
            }
            return new \Datetime('@' . strtotime($value), new \DateTimeZone('UTC'));
        });
        $contribution['start'] = $helper->ask($input, $output, $question);
        $question = new Question("[6/7] How would you describe the contribution? (multiline)\nUse EOL to finish, e.g. Ctrl+D on an empty line to finish input\n");
        $question->setMultiline(true);
        $question->setValidator([self::class, 'isNotEmpty']);
        $contribution['description'] = $helper->ask($input, $output, $question);
        $question = new Question("[7/7] Please provide public links related to the contribution? (one per line)\nUse EOL to finish, e.g. Ctrl+D on an empty line to finish input\n");
        $question->setMultiline(true);
        $question->setValidator([self::class, 'isNotEmpty']);
        $question->setNormalizer(function ($value) {
            $links = explode(PHP_EOL, $value);
            array_walk($links, 'trim');
            $links = array_filter($links);
            return $links;
        });
        $contribution['links'] = $helper->ask($input, $output, $question);
        return $contribution;
    }

    /**
     * Helper to get related contributor.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *   Input object.
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *   Output object.
     *
     * @return string
     *   The project to use, e.g. drupal/migrate_plus.
     */
    protected function getPerson(InputInterface $input, OutputInterface $output) {
        $helper = $this->getHelper('question');
        $people = array_keys($this->contributions['people']);
        array_unshift($people, self::UNDEFINED_PERSON);
        $question = new ChoiceQuestion(
            '[4/7] Who is making the the contribution?',
            $people,
            0
        );
        $question->setErrorMessage('Person %s is invalid.');
        $person = $helper->ask($input, $output, $question);
        if ($person == self::UNDEFINED_PERSON) {
            $question = new Question('What is the name of the person? (E.g. José Saramago): ');
            $question->setValidator([self::class, 'isNotEmpty']);
            $name = $helper->ask($input, $output, $question);
            $question = new Question('What is the identifier for the person? (E.g. Jose): ');
            $question->setValidator([self::class, 'isNotEmpty']);
            $machine_name = $helper->ask($input, $output, $question);
            $this->contributions['people'][$machine_name] = $name;
            $person = $machine_name;
        }
        return $person;
    }

    /**
     * Helper to adjust contributions data member before dump.
     */
    protected function prepareContributions() {
        // Sort people by identifier.
        ksort($this->contributions['people']);
        // Sort contributions by start date.
        usort($this->contributions['contributions'], function($item1, $item2) {
            if ($item1['start'] == $item2['start']) {
                return 0;
            }
            return ($item1['start'] < $item2['start']) ? -1 : 1;
        });
    }

    /**
     * Empty validator.
     */
    public static function isNotEmpty($value) {
        if (is_string($value)) {
            $value = trim($value);
        }
        if (empty($value)) {
            throw new \RuntimeException('Please provide a non-empty value.');
        }
        return $value;
    }

}