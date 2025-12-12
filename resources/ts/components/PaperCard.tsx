import { useState, useEffect, useRef, useCallback } from 'react';
import { ExternalLink, Sparkles, ChevronUp, Loader2, Tag, Plus, X } from 'lucide-react';
import api from '../api';
import type { Paper, Summary, Tag as TagType } from '../types';

interface PaperCardProps {
  paper: Paper;
  selectedProvider: string;
  onTagsChange?: () => void;
}

export default function PaperCard({ paper, selectedProvider, onTagsChange }: PaperCardProps): JSX.Element {
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(false);
  const [abstractExpanded, setAbstractExpanded] = useState(false);

  // タグ関連state
  const [tags, setTags] = useState<TagType[]>(paper.tags || []);
  const [showTagInput, setShowTagInput] = useState(false);
  const [tagInput, setTagInput] = useState('');
  const [addingTag, setAddingTag] = useState(false);
  const tagInputRef = useRef<HTMLInputElement>(null);

  // タグ候補（オートコンプリート）
  const [allTags, setAllTags] = useState<TagType[]>([]);
  const [selectedSuggestionIndex, setSelectedSuggestionIndex] = useState(-1);
  const suggestionsRef = useRef<HTMLDivElement>(null);
  const tagDropdownRef = useRef<HTMLDivElement>(null);

  // 外側クリックでドロップダウンを閉じる
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (tagDropdownRef.current && !tagDropdownRef.current.contains(event.target as Node)) {
        setShowTagInput(false);
        setTagInput('');
      }
    };
    if (showTagInput) {
      document.addEventListener('mousedown', handleClickOutside);
    }
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [showTagInput]);

  // 既存の要約がある場合は初期化時にセット
  useEffect(() => {
    if (paper.summaries && paper.summaries.length > 0) {
      // 最新の要約を使用
      setSummary(paper.summaries[0]);
    }
  }, [paper.summaries]);

  const hasSummary = summary !== null || (paper.summaries && paper.summaries.length > 0);

  const formatDate = (dateString: string | null): string => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日`;
  };

  const generateSummary = async (): Promise<void> => {
    setLoading(true);
    try {
      const data = await api.summaries.generate(paper.id, selectedProvider);
      if (data.success) {
        setSummary(data.summary);
        setExpanded(true);
      } else {
        throw new Error('Summary generation failed');
      }
    } catch (error) {
      alert('要約の生成に失敗しました: ' + (error as Error).message);
    } finally {
      setLoading(false);
    }
  };

  const authors = Array.isArray(paper.authors) ? paper.authors.join(', ') : paper.authors;

  // タグ追加
  const handleAddTag = async (): Promise<void> => {
    if (!tagInput.trim()) return;

    setAddingTag(true);
    try {
      const data = await api.tags.addToPaper(paper.id, { tag_name: tagInput.trim() });
      if (data.success) {
        setTags((prev) => [...prev, data.tag]);
        setTagInput('');
        setShowTagInput(false);
        onTagsChange?.();
      }
    } catch (error) {
      console.error('Failed to add tag:', error);
    } finally {
      setAddingTag(false);
    }
  };

  // タグ削除
  const handleRemoveTag = async (tagId: number): Promise<void> => {
    try {
      await api.tags.removeFromPaper(paper.id, tagId);
      setTags((prev) => prev.filter((t) => t.id !== tagId));
      onTagsChange?.();
    } catch (error) {
      console.error('Failed to remove tag:', error);
    }
  };

  // タグ一覧を取得
  const fetchAllTags = useCallback(async (): Promise<void> => {
    try {
      const data = await api.tags.list();
      if (data.success) {
        setAllTags(data.tags);
      }
    } catch (error) {
      console.error('Failed to fetch tags:', error);
    }
  }, []);

  // タグ入力欄が表示されたらタグ一覧を取得
  useEffect(() => {
    if (showTagInput) {
      fetchAllTags();
      tagInputRef.current?.focus();
    }
  }, [showTagInput, fetchAllTags]);

  // 入力に基づいてフィルタされたタグ候補
  const filteredSuggestions = allTags.filter((tag) => {
    // 既に付いているタグは除外
    if (tags.some((t) => t.id === tag.id)) return false;
    // 入力がない場合はすべて表示
    if (!tagInput.trim()) return true;
    // 入力にマッチするタグを表示
    return tag.name.toLowerCase().includes(tagInput.toLowerCase());
  });

  // 候補を選択してタグを追加
  const selectSuggestion = async (tag: TagType): Promise<void> => {
    setAddingTag(true);
    try {
      const data = await api.tags.addToPaper(paper.id, { tag_id: tag.id });
      if (data.success) {
        setTags((prev) => [...prev, data.tag]);
        setTagInput('');
        setShowTagInput(false);
        onTagsChange?.();
      }
    } catch (error) {
      console.error('Failed to add tag:', error);
    } finally {
      setAddingTag(false);
    }
  };

  // 入力変更時
  const handleTagInputChange = (value: string): void => {
    setTagInput(value);
    setSelectedSuggestionIndex(-1);
  };

  // アブストラクトが長いかどうかを判定（約150文字以上）
  const isAbstractLong = paper.abstract && paper.abstract.length > 150;

  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-200 hover:shadow-md transition-all">
      <div className="p-3 sm:p-5">
        <div className="flex items-start gap-2 sm:gap-4">
          {/* 色バー */}
          <div className={`w-1 sm:w-1.5 self-stretch rounded-full ${paper.journal_color}`} />

          {/* コンテンツ */}
          <div className="flex-1 min-w-0">
            {/* ヘッダー行: メタ情報（左）とタグ（右） */}
            <div className="flex items-start justify-between gap-2 mb-2">
              {/* メタ情報（左側） */}
              <div className="flex items-center gap-2 flex-wrap">
                <span className={`px-2 sm:px-2.5 py-0.5 sm:py-1 text-xs font-medium text-white rounded-lg ${paper.journal_color} leading-tight`}>
                  {paper.journal_name}
                </span>
                <span className="text-xs text-gray-500">
                  {formatDate(paper.published_date)}
                </span>
                {hasSummary && (
                  <span className="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full flex items-center gap-1">
                    <Sparkles className="w-3 h-3" />
                    要約済
                  </span>
                )}
              </div>

              {/* タグ（右上） */}
              <div className="flex items-center gap-1 flex-wrap justify-end relative">
                {tags.map((tag) => (
                  <span
                    key={tag.id}
                    className={`inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded-full ${tag.color || 'bg-gray-500'} text-white group`}
                  >
                    <Tag className="w-3 h-3" />
                    {tag.name}
                    <button
                      onClick={() => handleRemoveTag(tag.id)}
                      className="opacity-0 group-hover:opacity-100 transition-opacity ml-0.5 hover:bg-white/20 rounded-full p-0.5"
                      title="Tagを削除"
                    >
                      <X className="w-3 h-3" />
                    </button>
                  </span>
                ))}
                {/* タグ追加ボタン/ドロップダウン */}
                <div className="relative" ref={tagDropdownRef}>
                  <button
                    onClick={() => setShowTagInput(!showTagInput)}
                    className={`inline-flex items-center gap-0.5 px-2 py-0.5 text-xs rounded-full border transition-colors ${
                      showTagInput
                        ? 'text-indigo-600 bg-indigo-50 border-indigo-300'
                        : 'text-gray-500 hover:text-indigo-600 hover:bg-indigo-50 border-dashed border-gray-300 hover:border-indigo-300'
                    }`}
                  >
                    <Plus className="w-3 h-3" />
                    Tag
                  </button>

                  {/* タグ選択ドロップダウン */}
                  {showTagInput && (
                    <div
                      ref={suggestionsRef}
                      className="absolute top-full right-0 mt-1 w-56 bg-white border border-gray-200 rounded-lg shadow-lg z-20"
                    >
                      {/* 検索入力 */}
                      <div className="p-2 border-b border-gray-100">
                        <input
                          ref={tagInputRef}
                          type="text"
                          value={tagInput}
                          onChange={(e) => handleTagInputChange(e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key === 'ArrowDown') {
                              e.preventDefault();
                              setSelectedSuggestionIndex((prev) =>
                                prev < filteredSuggestions.length - 1 ? prev + 1 : prev
                              );
                            } else if (e.key === 'ArrowUp') {
                              e.preventDefault();
                              setSelectedSuggestionIndex((prev) => (prev > 0 ? prev - 1 : -1));
                            } else if (e.key === 'Enter') {
                              e.preventDefault();
                              if (selectedSuggestionIndex >= 0 && filteredSuggestions[selectedSuggestionIndex]) {
                                selectSuggestion(filteredSuggestions[selectedSuggestionIndex]);
                              } else if (tagInput.trim()) {
                                handleAddTag();
                              }
                            } else if (e.key === 'Escape') {
                              setShowTagInput(false);
                              setTagInput('');
                            }
                          }}
                          placeholder="Tagを検索または新規作成..."
                          className="w-full px-2 py-1.5 text-xs border border-gray-300 rounded focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                          disabled={addingTag}
                        />
                      </div>

                      {/* 既存タグ一覧 */}
                      <div className="max-h-48 overflow-y-auto">
                        {filteredSuggestions.length > 0 ? (
                          filteredSuggestions.slice(0, 10).map((tag, index) => (
                            <button
                              key={tag.id}
                              onClick={() => selectSuggestion(tag)}
                              disabled={addingTag}
                              className={`w-full px-3 py-2 text-left text-xs flex items-center gap-2 hover:bg-indigo-50 transition-colors disabled:opacity-50 ${
                                index === selectedSuggestionIndex ? 'bg-indigo-50' : ''
                              }`}
                            >
                              <span className={`w-2.5 h-2.5 rounded-full flex-shrink-0 ${tag.color}`} />
                              <span className="truncate flex-1">{tag.name}</span>
                              <span className="text-gray-400 text-[10px]">{tag.paper_count || 0}件</span>
                            </button>
                          ))
                        ) : (
                          <div className="px-3 py-2 text-xs text-gray-500">
                            {allTags.length === 0 ? 'Tagがありません' : '該当するTagがありません'}
                          </div>
                        )}
                      </div>

                      {/* 新規作成オプション */}
                      {tagInput.trim() && !filteredSuggestions.some(t => t.name.toLowerCase() === tagInput.toLowerCase()) && (
                        <div className="border-t border-gray-100">
                          <button
                            onClick={handleAddTag}
                            disabled={addingTag}
                            className="w-full px-3 py-2 text-left text-xs flex items-center gap-2 hover:bg-green-50 text-green-700 disabled:opacity-50"
                          >
                            {addingTag ? (
                              <Loader2 className="w-3 h-3 animate-spin" />
                            ) : (
                              <Plus className="w-3 h-3" />
                            )}
                            「{tagInput}」を新規作成
                          </button>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>
            </div>

            {/* タイトル */}
            <h3 className="text-base sm:text-lg font-semibold text-gray-900 mb-2 leading-snug">
              {paper.title}
            </h3>

            {/* 著者 */}
            <p className="text-xs sm:text-sm text-gray-600 mb-3 line-clamp-2 sm:line-clamp-none">
              {authors}
            </p>

            {/* アブストラクト */}
            {paper.abstract && (
              <div className="mb-4">
                <p className={`text-xs sm:text-sm text-gray-700 ${!abstractExpanded && isAbstractLong ? 'line-clamp-3' : ''}`}>
                  {paper.abstract}
                </p>
                {isAbstractLong && (
                  <button
                    onClick={() => setAbstractExpanded(!abstractExpanded)}
                    className="text-xs text-indigo-600 hover:text-indigo-800 mt-1 font-medium"
                  >
                    {abstractExpanded ? '概要を折りたたむ' : '概要を全文表示'}
                  </button>
                )}
              </div>
            )}

            {/* アクション */}
            <div className="flex items-center gap-2 sm:gap-3 flex-wrap">
              {/* AI要約ボタン：要約がない場合は生成ボタン，ある場合は表示トグル */}
              {!hasSummary ? (
                <button
                  onClick={generateSummary}
                  disabled={loading}
                  className="flex items-center gap-1.5 sm:gap-2 px-3 sm:px-4 py-1.5 sm:py-2 bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-xs sm:text-sm rounded-lg hover:from-indigo-600 hover:to-purple-600 disabled:opacity-50 disabled:cursor-not-allowed font-medium transition-all"
                >
                  {loading ? (
                    <Loader2 className="w-3.5 h-3.5 sm:w-4 sm:h-4 animate-spin" />
                  ) : (
                    <Sparkles className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                  )}
                  {loading ? '生成中...' : 'AI要約'}
                </button>
              ) : (
                <button
                  onClick={() => setExpanded(!expanded)}
                  className="flex items-center gap-1.5 sm:gap-2 px-3 sm:px-4 py-1.5 sm:py-2 bg-gradient-to-r from-green-500 to-emerald-500 text-white text-xs sm:text-sm rounded-lg hover:from-green-600 hover:to-emerald-600 font-medium transition-all"
                >
                  {expanded ? (
                    <ChevronUp className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                  ) : (
                    <Sparkles className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                  )}
                  <span className="hidden xs:inline">{expanded ? 'AI要約を閉じる' : 'AI要約を表示'}</span>
                  <span className="xs:hidden">{expanded ? '閉じる' : '要約'}</span>
                </button>
              )}

              <a
                href={paper.url || '#'}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center gap-1 px-2 sm:px-3 py-1.5 sm:py-2 text-xs sm:text-sm text-gray-600 hover:text-indigo-600 transition-colors"
              >
                <ExternalLink className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                <span className="hidden sm:inline">論文を開く</span>
                <span className="sm:hidden">開く</span>
              </a>
            </div>

            {/* AI要約 */}
            {summary && expanded && (
              <div className="mt-3 sm:mt-4 p-3 sm:p-5 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-xl border border-indigo-100">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3 sm:mb-4">
                  <div className="flex items-center gap-2">
                    <Sparkles className="w-4 h-4 sm:w-5 sm:h-5 text-indigo-600" />
                    <span className="font-medium text-indigo-900 text-sm sm:text-base">AI要約</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-xs px-2 py-1 bg-indigo-100 rounded-full text-indigo-600">
                      {summary.ai_provider}
                    </span>
                    {summary.ai_model && (
                      <span className="text-xs text-gray-500 hidden sm:inline">
                        {summary.ai_model}
                      </span>
                    )}
                  </div>
                </div>
                <div className="space-y-3 sm:space-y-4 text-xs sm:text-sm text-gray-800">
                  {summary.purpose && (
                    <div>
                      <span className="font-semibold text-indigo-700">【研究目的】</span>
                      <p className="mt-1">{summary.purpose}</p>
                    </div>
                  )}
                  {summary.methodology && (
                    <div>
                      <span className="font-semibold text-indigo-700">【手法】</span>
                      <p className="mt-1">{summary.methodology}</p>
                    </div>
                  )}
                  {summary.findings && (
                    <div>
                      <span className="font-semibold text-indigo-700">【主な発見】</span>
                      <p className="mt-1">{summary.findings}</p>
                    </div>
                  )}
                  {summary.implications && (
                    <div>
                      <span className="font-semibold text-indigo-700">【教育への示唆】</span>
                      <p className="mt-1">{summary.implications}</p>
                    </div>
                  )}
                  {!summary.purpose && summary.summary_text && (
                    <p className="whitespace-pre-wrap">{summary.summary_text}</p>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
