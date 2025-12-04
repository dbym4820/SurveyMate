/**
 * AI要約生成モジュール
 * Claude API / OpenAI API 両対応
 */
import logger from './logger.js';
// プロバイダ設定
const AI_PROVIDERS = {
    claude: {
        baseUrl: 'https://api.anthropic.com/v1/messages',
        defaultModel: 'claude-sonnet-4-5-20250929',
    },
    openai: {
        baseUrl: 'https://api.openai.com/v1/chat/completions',
        defaultModel: 'gpt-4o',
    },
};
/**
 * 要約生成用プロンプト
 */
function createSummaryPrompt(paper) {
    const authors = Array.isArray(paper.authors) ? paper.authors.join(', ') : paper.authors;
    return `以下の学術論文について，日本語で簡潔に要約してください．

要約は以下のJSON形式で出力してください（JSONのみを出力し，他のテキストは含めないでください）：

{
  "summary_text": "全体の要約（3-4文）",
  "purpose": "研究目的（1-2文）",
  "methodology": "研究手法（1-2文）",
  "findings": "主な発見（2-3文）",
  "implications": "教育への示唆（1文）"
}

論文情報：
- タイトル: ${paper.title}
- 著者: ${authors}
- 論文誌: ${paper.journal_full_name || paper.journal_name || ''}
- アブストラクト: ${paper.abstract || '（なし）'}

注意事項：
- 句読点は「，」と「．」を使用してください
- 専門用語は適切に日本語訳してください
- 教育・学習研究の観点から要約してください`;
}
/**
 * Claude APIで要約を生成
 */
async function generateWithClaude(paper, model) {
    const apiKey = process.env.CLAUDE_API_KEY;
    if (!apiKey) {
        throw new Error('CLAUDE_API_KEY is not set');
    }
    const modelName = model || process.env.CLAUDE_MODEL || AI_PROVIDERS.claude.defaultModel;
    const prompt = createSummaryPrompt(paper);
    const response = await fetch(AI_PROVIDERS.claude.baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'x-api-key': apiKey,
            'anthropic-version': '2023-06-01',
        },
        body: JSON.stringify({
            model: modelName,
            max_tokens: 2000,
            messages: [
                { role: 'user', content: prompt }
            ],
        }),
    });
    if (!response.ok) {
        const error = await response.text();
        throw new Error(`Claude API error: ${response.status} - ${error}`);
    }
    const data = await response.json();
    const content = data.content[0].text;
    // トークン使用量
    const tokensUsed = data.usage
        ? data.usage.input_tokens + data.usage.output_tokens
        : null;
    return {
        content,
        tokensUsed,
        model: modelName,
    };
}
/**
 * OpenAI APIで要約を生成
 */
async function generateWithOpenAI(paper, model) {
    const apiKey = process.env.OPENAI_API_KEY;
    if (!apiKey) {
        throw new Error('OPENAI_API_KEY is not set');
    }
    const modelName = model || process.env.OPENAI_MODEL || AI_PROVIDERS.openai.defaultModel;
    const prompt = createSummaryPrompt(paper);
    const response = await fetch(AI_PROVIDERS.openai.baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${apiKey}`,
        },
        body: JSON.stringify({
            model: modelName,
            messages: [
                { role: 'user', content: prompt }
            ],
            max_tokens: 2000,
            temperature: 0.3,
        }),
    });
    if (!response.ok) {
        const error = await response.text();
        throw new Error(`OpenAI API error: ${response.status} - ${error}`);
    }
    const data = await response.json();
    const content = data.choices[0].message.content;
    // トークン使用量
    const tokensUsed = data.usage?.total_tokens || null;
    return {
        content,
        tokensUsed,
        model: modelName,
    };
}
/**
 * JSONレスポンスをパース
 */
function parseResponse(content) {
    // JSONブロックを抽出
    let jsonStr = content;
    // ```json ... ``` を除去
    const jsonMatch = content.match(/```json\s*([\s\S]*?)\s*```/);
    if (jsonMatch) {
        jsonStr = jsonMatch[1];
    }
    else {
        // ``` ... ``` を除去
        const codeMatch = content.match(/```\s*([\s\S]*?)\s*```/);
        if (codeMatch) {
            jsonStr = codeMatch[1];
        }
    }
    // 前後の空白を除去
    jsonStr = jsonStr.trim();
    try {
        return JSON.parse(jsonStr);
    }
    catch {
        logger.warn('Failed to parse JSON response, using raw content');
        return {
            summary_text: content,
            purpose: null,
            methodology: null,
            findings: null,
            implications: null,
        };
    }
}
/**
 * 要約を生成（メイン関数）
 */
export async function generateSummary(paper, options = {}) {
    const startTime = Date.now();
    // プロバイダを決定
    const provider = options.provider || process.env.AI_PROVIDER || 'claude';
    const model = options.model || undefined;
    logger.info('Generating summary', {
        paperId: paper.id,
        provider,
        model,
    });
    let result;
    try {
        switch (provider.toLowerCase()) {
            case 'claude':
            case 'anthropic':
                result = await generateWithClaude(paper, model);
                break;
            case 'openai':
            case 'gpt':
                result = await generateWithOpenAI(paper, model);
                break;
            default:
                throw new Error(`Unknown AI provider: ${provider}`);
        }
    }
    catch (error) {
        logger.error('Summary generation failed', {
            paperId: paper.id,
            provider,
            error: error.message,
        });
        throw error;
    }
    const generationTime = Date.now() - startTime;
    // レスポンスをパース
    const parsed = parseResponse(result.content);
    logger.info('Summary generated', {
        paperId: paper.id,
        provider,
        model: result.model,
        tokensUsed: result.tokensUsed,
        generationTime: `${generationTime}ms`,
    });
    return {
        ai_provider: provider,
        ai_model: result.model,
        summary_text: parsed.summary_text || result.content,
        purpose: parsed.purpose,
        methodology: parsed.methodology,
        findings: parsed.findings,
        implications: parsed.implications,
        tokens_used: result.tokensUsed,
        generation_time_ms: generationTime,
    };
}
/**
 * 利用可能なプロバイダを取得
 */
export function getAvailableProviders() {
    const providers = [];
    if (process.env.CLAUDE_API_KEY) {
        providers.push({
            id: 'claude',
            name: 'Claude (Anthropic)',
            models: [
                'claude-sonnet-4-5-20250929',
                'claude-3-5-sonnet-20241022',
                'claude-3-haiku-20240307',
            ],
            defaultModel: process.env.CLAUDE_MODEL || AI_PROVIDERS.claude.defaultModel,
        });
    }
    if (process.env.OPENAI_API_KEY) {
        providers.push({
            id: 'openai',
            name: 'OpenAI',
            models: [
                'gpt-4o',
                'gpt-4o-mini',
                'gpt-4-turbo',
            ],
            defaultModel: process.env.OPENAI_MODEL || AI_PROVIDERS.openai.defaultModel,
        });
    }
    return providers;
}
/**
 * 現在のプロバイダ設定を取得
 */
export function getCurrentProvider() {
    return process.env.AI_PROVIDER || 'claude';
}
export default {
    generateSummary,
    getAvailableProviders,
    getCurrentProvider,
};
//# sourceMappingURL=ai-summary.js.map