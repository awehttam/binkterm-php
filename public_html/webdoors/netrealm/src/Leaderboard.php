<?php

/**
 * Leaderboard rankings for NetRealm RPG.
 *
 * Provides multiple ranking types: overall, pvp, wealth, monster_slayer.
 */
class Leaderboard
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get leaderboard rankings.
     *
     * @param string $type overall|pvp|wealth|monster_slayer
     * @param int $limit
     * @return array
     */
    public function getRankings(string $type = 'overall', int $limit = 20): array
    {
        switch ($type) {
            case 'pvp':
                return $this->getPvpRankings($limit);
            case 'wealth':
                return $this->getWealthRankings($limit);
            case 'monster_slayer':
                return $this->getMonsterSlayerRankings($limit);
            case 'overall':
            default:
                return $this->getOverallRankings($limit);
        }
    }

    /**
     * Overall rankings by level and XP.
     *
     * @param int $limit
     * @return array
     */
    private function getOverallRankings(int $limit): array
    {
        $stmt = $this->db->prepare('
            SELECT name, level, xp, pvp_wins, pvp_losses, monsters_killed, gold
            FROM netrealm_characters
            ORDER BY level DESC, xp DESC
            LIMIT ?
        ');
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * PvP rankings by wins (minimum 5 total fights).
     *
     * @param int $limit
     * @return array
     */
    private function getPvpRankings(int $limit): array
    {
        $stmt = $this->db->prepare('
            SELECT name, level, pvp_wins, pvp_losses,
                   CASE WHEN (pvp_wins + pvp_losses) > 0
                        THEN ROUND(pvp_wins::NUMERIC / (pvp_wins + pvp_losses) * 100, 1)
                        ELSE 0 END AS win_rate
            FROM netrealm_characters
            WHERE (pvp_wins + pvp_losses) >= 5
            ORDER BY pvp_wins DESC, win_rate DESC
            LIMIT ?
        ');
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Wealth rankings by gold.
     *
     * @param int $limit
     * @return array
     */
    private function getWealthRankings(int $limit): array
    {
        $stmt = $this->db->prepare('
            SELECT name, level, gold
            FROM netrealm_characters
            ORDER BY gold DESC
            LIMIT ?
        ');
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Monster slayer rankings by kill count.
     *
     * @param int $limit
     * @return array
     */
    private function getMonsterSlayerRankings(int $limit): array
    {
        $stmt = $this->db->prepare('
            SELECT name, level, monsters_killed
            FROM netrealm_characters
            ORDER BY monsters_killed DESC
            LIMIT ?
        ');
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
