<?php

namespace Database\Factories;

use App\Models\Documentation;
use App\Models\Scope;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentationFactory extends Factory
{
    protected $model = Documentation::class;

    public function definition(): array
    {
        return [
            'type' => Scope::factory(),
            'title' => fake()->unique()->sentence(3),
            'url' => fake()->url(),
            'uri' => null,
            // 預設不給內文:現有資料裡的 Documentation 幾乎都是外部官方文件（sourcesite）,
            // 那種本來就沒有內文。要測內文的案例自己指定,或用 withBody() 狀態
            'body' => null,
            'note' => fake()->optional()->sentence(),
            'status' => 1,
            'creation_date' => fake()->date(),
        ];
    }

    /** 有 Markdown 內文的文章（scope `post`）。 */
    public function withBody(?string $markdown = null): static
    {
        return $this->state(fn () => [
            'body' => $markdown ?? "## 段落一\n\n這是一段**內文**，裡面有 `code`。\n\n## 段落二\n\n- 項目\n- 項目\n",
        ]);
    }
}
