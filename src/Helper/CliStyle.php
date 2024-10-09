<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Helper;

use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataConnectorSW6\DTO\ImportCommandCliOptionsDTO;
use Topdata\TopdataConnectorSW6\Util\UtilArray;
use Topdata\TopdataConnectorSW6\Util\UtilString;

/**
 * 05/2022 created (copied from art-experiments).
 *
 * @version 2023-04-25
 */
class CliStyle extends SymfonyStyle
{

    /**
     * 06/2021 created, source: https://gist.github.com/superbrothers/3431198.
     * 11/2023 moved from AnsiColor to CliStyle
     *
     * php-ansi-color
     *
     * Original
     *     https://github.com/loopj/commonjs-ansi-color
     *
     * example usage:
     *      echo $this->colorText("Success", "green+bold") . " Something was successful!");
     */
    private const ANSI_CODES = [
        'off'        => 0,
        'bold'       => 1,
        'italic'     => 3,
        'underline'  => 4,
        'blink'      => 5,
        'inverse'    => 7,
        'hidden'     => 8,
        // ---------------
        'black'      => 30,
        'red'        => 31,
        'green'      => 32,
        'yellow'     => 33,
        'blue'       => 34,
        'magenta'    => 35,
        'cyan'       => 36,
        'white'      => 37,
        // ---------------
        'black_bg'   => 40,
        'red_bg'     => 41,
        'green_bg'   => 42,
        'yellow_bg'  => 43,
        'blue_bg'    => 44,
        'magenta_bg' => 45,
        'cyan_bg'    => 46,
        'white_bg'   => 47,
    ];

    private InputInterface $input;
    private OutputInterface $output;

    /**
     * 01/2023 created
     * 11/2023 moved from AnsiColor to CliStyle
     * 12/2023 making it non-static, adding check $this->isDecorated
     */
    public function colorText(string $msg, string $color): string
    {
        // no ansi, just return plain text
        if (!$this->isDecorated()) {
            return $msg;
        }

        $color_attrs = explode('+', $color);
        $ansi_str = '';
        foreach ($color_attrs as $attr) {
            $ansi_str .= "\033[" . self::ANSI_CODES[$attr] . 'm';
        }
        $ansi_str .= $msg . "\033[" . self::ANSI_CODES['off'] . 'm';

        return $ansi_str;
    }

//    public static function replace($full_text, $search_regexp, $color)
//    {
//        $new_text = preg_replace_callback(
//            "/($search_regexp)/",
//            function ($matches) use ($color) {
//                return self::set($matches[1], $color);
//            },
//            $full_text
//        );
//        return is_null($new_text) ? $full_text : $new_text;
//    }


    /**
     * wrapper to have an instance of input and output
     * 04/2022 created.
     */
    public function __construct(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        // ---- custom styles, see https://symfony.com/doc/current/console/coloring.html#using-color-styles
        $this->output->getFormatter()->setStyle('fire', new OutputFormatterStyle('red', '#ff0', ['bold', 'blink']));

        parent::__construct($input, $output);
    }

    /**
     * 04/2023 created
     *
     * @param mixed $val
     * @return false|string
     */
    private static function _nonScalarToStringForTable(mixed $val)
    {
        return json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 08/2023 created
     *
     * @param array $rows
     * @return array new rows with non-scalar values converted to string
     */
    public function _fixNonScalarTableCells(array $rows): array
    {
        $rowsFixed = [];
        foreach ($rows as $row) {
            $newRow = [];
            foreach ($row as $idx => $val) {
                if (is_scalar($val)) {
                    $newRow[] = $val;
                } else {
                    $newRow[] = self::_nonScalarToStringForTable($val);
                }
            }
            $rowsFixed[] = $newRow;
        }

        return $rowsFixed;
    }


    /**
     * same like SymfonyStyle's table but with optional headerTitle and footerTitle
     *
     * 07/2023 created
     */
    #[\Override]
    public function table(array $headers, array $rows, ?string $headerTitle = null, ?string $footerTitle = null): void
    {
        $rowsFixed = $this->_fixNonScalarTableCells($rows);

        $tbl = $this->createTable()
            ->setHeaders($headers)
            ->setRows($rowsFixed);

        if ($headerTitle) {
            $tbl->setHeaderTitle($headerTitle);
        }

        if ($footerTitle) {
            $tbl->setFooterTitle($footerTitle);
        }

        $tbl->render();

        $this->newLine();
    }


    /**
     * Formats a horizontal table.
     *
     * same like SymfonyStyle's table but with optional headerTitle and footerTitle
     *
     * 07/2023 created
     */
    #[\Override]
    public function horizontalTable(array $headers, array $rows, ?string $headerTitle = null, ?string $footerTitle = null): void
    {
        // ---- rectify non-scalar values

        $rowsFixed = $this->_fixNonScalarTableCells($rows);

        $tbl = $this->createTable()
            ->setHorizontal(true)
            ->setHeaders($headers)
            ->setRows($rowsFixed);

        if ($headerTitle) {
            $tbl->setHeaderTitle($headerTitle);
        }

        if ($footerTitle) {
            $tbl->setFooterTitle($footerTitle);
        }


        $tbl->render();

        $this->newLine();
    }


    /**
     * 03/2022 TODO: some color as parameter would be nice (see UtilFormatter for ascii tables as alternative to symfony's table)
     * 11/2020 created.
     * 12/2023 added param $bFlatten
     * 01/2024 added optional conversion from object to assoc
     * 01/2024 added parameter $maxLength which cuts too long strings
     * 03/2024 renamed dictAsHorizontalTable --> dumpDict
     */
    public function dumpDict(array|object|null $dict, ?string $title = null, bool $bFlatten = true, ?int $maxLength = 80): void
    {
        // optional conversion from object to assoc
        if (is_object($dict)) {
            $dict = (array)$dict;
        }

        if (!$dict) {
            $this->warning('dictAsHorizontalTable' . ($title ? (' ' . $title) : '') . ' - $dict is empty');

            return;
        }

        if ($bFlatten) {
            $dict = UtilArray::flatten($dict);
        }

        // ---- cut too long strings
        if($maxLength) {
            foreach ($dict as $key => $val) {
                if (is_string($val)) {
                    $dict[$key] = UtilString::maxLength($val, $maxLength);
                }
            }
        }

        $values = array_values($dict);

        $this->horizontalTable(array_keys($dict), [$values], $title);
    }

    /**
     * factory method.
     *
     * 01/2022 created
     *
     * @return self
     */
    public static function createQuiet()
    {
        return new self(new ArgvInput(), new NullOutput());
    }

    /**
     * factory method.
     *
     * 01/2022 created
     *
     * @return self
     */
    public static function create()
    {
        // return new self(new ArgvInput(), new StreamOutput(fopen('php://stdout', 'w')));
        return new self(new ArgvInput(), new ConsoleOutput());
    }

    /**
     * 05/2021 created.
     */
    public function done(string|null $msg = "DONE"): void
    {
        $this->success("==== $msg ====");
    }

    /**
     * 01/2023 created
     */
    public function fail(): void
    {
        $this->error('!!!!!!!!!!!!!!!! FAIL !!!!!!!!!!!!!!!!');
    }

    public function red(string $msg, $bNewLine = true): void
    {
        $this->write($this->colorText($msg, 'red'), $bNewLine);
    }

    public function green(string $msg, $bNewLine = true): void
    {
        $this->write($this->colorText($msg, 'green'), $bNewLine);
    }

    public function blue(string $msg, $bNewLine = true): void
    {
        $this->write($this->colorText($msg, 'blue'), $bNewLine);
    }

    public function yellow(string $msg, $bNewLine = true): void
    {
        $this->write($this->colorText($msg, 'yellow'), $bNewLine);
    }

    public function cyan(string $msg, $bNewLine = true): void
    {
        $this->write($this->colorText($msg, 'cyan'), $bNewLine);
    }

    public function magenta(string $msg, $bNewLine = true): void
    {
        $this->write($this->colorText($msg, 'magenta'), $bNewLine);
    }

    public function red_bg(string $msg, $bNewLine = true): void
    {
        $this->write($this->colorText($msg, 'red_bg'), $bNewLine);
    }

    public function green_bg(string $msg, $bNewLine = true): void
    {
        $this->write($this->colorText($msg, 'green_bg'), $bNewLine);
    }

    public function blue_bg(string $msg, $bNewLine = true): void
    {
        $this->write($this->colorText($msg, 'blue_bg'), $bNewLine);
    }

    public function yellow_bg(string $msg, $bNewLine = true): void
    {
        $this->write($this->colorText($msg, 'yellow_bg'), $bNewLine);
    }

    public function cyan_bg(string $msg, $bNewLine = true): void
    {
        $this->write($this->colorText($msg, 'cyan_bg'), $bNewLine);
    }

    public function magenta_bg(string $msg, $bNewLine = true): void
    {
        $this->write($this->colorText($msg, 'magenta_bg'), $bNewLine);
    }


    /**
     * same like SymfonyStyle's definitionList but with optional headerTitle and footerTitle
     * copied from SymfonyStyle::definitionList() and modified
     *
     * 01/2024 created
     *
     */
    public function myDefinitionList(array $list, ?string $headerTitle = null, ?string $footerTitle = null): void
    {
        $headers = [];
        $row = [];
        foreach ($list as $value) {
            if ($value instanceof TableSeparator) {
                $headers[] = $value;
                $row[] = $value;
                continue;
            }
            if (\is_string($value)) {
                $headers[] = new TableCell($value, ['colspan' => 2]);
                $row[] = null;
                continue;
            }
            if (!\is_array($value)) {
                throw new InvalidArgumentException('Value should be an array, string, or an instance of TableSeparator.');
            }
            $headers[] = key($value);
            $row[] = current($value);
        }

        $this->horizontalTable($headers, [$row], $headerTitle, $footerTitle);
    }


    /**
     * 04/2022 created.
     * 01/2024 optional headerTitle and footerTitle added
     *
     * @param string[] $arr
     */
    public function list(array $arr, ?string $headerTitle = null, ?string $footerTitle = null): void
    {
        $rows = [];
        foreach ($arr as $key => $item) {
            $rows[] = [(string)($key + 1) => $item];
        }
        $this->myDefinitionList($rows, $headerTitle, $footerTitle);
    }

    /**
     * 03/2023 created
     *
     * @param array $dicts numeric array of dicts
     */
    public function listOfDictsAsTable(array $dicts, ?string $title = null): void
    {
        if (empty($dicts)) {
            $this->warning("listOfDictsAsTable - dict is empty");
            return;
        }

        $this->table(array_keys($dicts[0]), $dicts, $title);
    }


    /**
     * Lets the user type some confirmation string (eg the domain name of a shop the command is going to operate on)
     * if the entered string does not match the expected $confirmation string, the script EXITS with code 77
     * 05/2023 created
     *
     * @param string $confirmationString
     * @param string $info
     */
    public function confirmSecureOrDie(string $confirmationString, string $info = null): void
    {
        if ($info) {
            $this->writeln("<info>$info</info>");
        }

        // ---- ask user to confirm by typing some text
        $response = $this->askQuestion(new Question("To continue, type <question>$confirmationString</question>", null));

        if ($response !== $confirmationString) {
            $this->error("expected: $confirmationString, got: $response ... exiting");
            exit(77);
        }
    }


    public function getInput(): InputInterface
    {
        return $this->input;
    }

    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    /**
     * TODO: try using UtilSyntaxHighlightingCli::highlightSourceCode()
     *
     * 01/2024 created
     * 02/2024 renamed writeSql --> dumpSql
     *
     * @param string|string[] $sql
     */
    public function dumpSql(string|array $sql): void
    {
        if(is_string($sql)) {
            $sql = [$sql];
        }
        foreach($sql as $s) {
            $this->writeln(\SqlFormatter::format($s));
        }
    }

    /**
     * 03/2024 created
     */
    public function divider(?string $title = null, int $paddingY = 3): void
    {
        $this->newLine($paddingY);

        $widthTotal = 180;

        if(empty($title)) {
            $this->writeln(str_repeat('-', $widthTotal));
        } else {
            $lenTitle = strlen($title);
            $remaining = $widthTotal - $lenTitle;
            $left = (int)($remaining / 2);
            $right = $remaining - $left;
            $this->writeln(str_repeat('-', $left) . ' ' . $title . ' ' . str_repeat('-', $right));
        }

        $this->newLine($paddingY);
    }

    /**
     * just a demo of styling output with tags
     *
     * 04/2024 created
     */
    public function exampleStyledOutputs(): void
    {

         // green text
        $this->writeln(trim("

<info>info - yellow</info> ... 
<comment>comment - green</comment> ... 
<question>question - cyan background</question> ... 
<error>error - red background</error> ...
<fire>fire</fire> ...

        "));
    }


}
