<?php

use App\Deploy\Actions\ActionDefinition;
use App\Deploy\Actions\ActionParser;
use Symfony\Component\Yaml\Yaml;

const REINDEX_YAML = <<<'YAML'
    name: Reindex search
    on:
      manual: true
      schedule: "30 4 * * 0"
      target: box
    inputs:
      index: { type: choice, options: [products, orders, all], default: products }
      fresh: { type: boolean, default: false, description: flush the index first? }
      token: secret
    steps:
      - name: Flush the index
        if: inputs.fresh
        exec: api
        run: php artisan scout:flush "$INDEX"
      - name: Import
        one-off: worker
        run: php artisan scout:import "$INDEX"
        timeout: 15m
        continue-on-error: true
      - php artisan optimize:clear
    YAML;

it('reads an action file into its definition', function () {
    [$definition, $errors] = ActionParser::yaml(REINDEX_YAML);

    expect($errors)->toBe([])
        ->and($definition)->toBeInstanceOf(ActionDefinition::class)
        ->and($definition->name())->toBe('Reindex search')
        ->and($definition->manual())->toBeTrue()
        ->and($definition->schedule())->toBe('30 4 * * 0')
        ->and($definition->scheduleTarget())->toBe('box')
        ->and($definition->scheduleLabel())->toBe('Sundays 04:30')
        ->and($definition->passkey())->toBeFalse()
        ->and($definition->mcp())->toBeFalse()
        ->and($definition->alert())->toBeTrue()
        ->and(array_column($definition->inputs(), 'type', 'name'))->toBe(['index' => 'choice', 'fresh' => 'boolean', 'token' => 'secret'])
        ->and($definition->input('fresh')['default'])->toBeFalse()
        ->and($definition->steps())->toHaveCount(3)
        ->and($definition->steps()[0])->toMatchArray(['run_in' => 'service_exec', 'service' => 'api', 'if' => 'fresh', 'timeout' => 600])
        ->and($definition->steps()[1])->toMatchArray(['run_in' => 'service_run', 'service' => 'worker', 'timeout' => 900, 'continue_on_error' => true])
        ->and($definition->steps()[2])->toMatchArray(['name' => 'php artisan optimize:clear', 'run_in' => 'host']);
});

it('writes a definition back as YAML that reads the same', function () {
    [$definition] = ActionParser::yaml(REINDEX_YAML);
    [$again, $errors] = ActionParser::array(Yaml::parse(Yaml::dump($definition->toYaml(), 4, 2)));

    expect($errors)->toBe([])->and($again->data)->toBe($definition->data);
});

it('lists every problem in a file, not just the first', function () {
    [$definition, $errors] = ActionParser::yaml(<<<'YAML'
        name: Broken
        colour: blue
        on: { schedule: "every day", after_deploy: sometimes }
        inputs:
          days: { type: number, default: soon }
          kind: { type: choice }
          password: { type: secret, default: hunter2 }
        steps:
          - run: echo hi
            exec: api
            one-off: worker
          - run: echo again
            shell: zsh
            if: inputs.days
            timeout: 2d
        YAML);

    expect($definition)->toBeNull()
        ->and(implode("\n", $errors))->toContain(
            'colour: not a key deploy.me knows',
            'on.schedule: “every day” isn’t a cron expression',
            'on.after_deploy: succeeded, failed or either',
            'inputs.days.default: a whole number',
            'inputs.kind.options: a choice needs a list of options',
            'inputs.password.default: a secret has no default',
            'steps.1: exec or one-off, not both',
            'steps.2.shell: not a key deploy.me knows',
            'steps.2.if: inputs.<name> of a boolean input',
            'steps.2.timeout: between 10s and 1h',
        );
});

it('refuses a file that isn’t YAML, or has no steps', function () {
    expect(ActionParser::yaml("name: [unclosed\n")[1][0])->toStartWith('YAML: ')
        ->and(ActionParser::yaml("- a\n- b\n")[1][0])->toContain('should be a mapping')
        ->and(implode(' ', ActionParser::yaml("name: Nothing\n")[1]))->toContain('steps: a list of at least one step');
});

it('keeps an action that asks for a passkey to people', function () {
    [$definition, $errors] = ActionParser::yaml(<<<'YAML'
        name: Flush failed jobs
        on: { manual: true, schedule: "0 3 * * *", after_deploy: either }
        confirm: passkey
        mcp: true
        steps: [php artisan queue:flush]
        YAML);

    expect($definition)->toBeNull()
        ->and(implode("\n", $errors))->toContain('on.schedule: an action that asks for a passkey', 'on.after_deploy: an action that asks for a passkey', 'mcp: an action that asks for a passkey can’t be started by an agent');
});

it('needs something to start it', function () {
    expect(implode(' ', ActionParser::yaml("name: Never\non: { manual: false }\nsteps: [true]\n")[1]))->toContain('nothing would ever start it');
});

it('turns inputs into environment variables', function () {
    expect(ActionDefinition::envName('keep-days'))->toBe('KEEP_DAYS')
        ->and(implode(' ', ActionParser::yaml("name: X\ninputs: { deployme_dir: text }\nsteps: [true]\n")[1]))->toContain('DEPLOYME_DIR is deploy.me’s own');
});
